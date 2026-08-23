<?php

declare(strict_types=1);

namespace App\Actions\Cashback;

use App\Data\Cashback\RecordedPaystackWebhook;
use App\Data\Payments\PaystackTransferCallback;
use App\Enums\CashbackRewardStatus;
use App\Enums\CashbackTransferErrorCode;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PayoutAttemptStatus;
use App\Enums\PaystackTransferEvent;
use App\Enums\ProviderWebhookReceiptResult;
use App\Models\CashbackReward;
use App\Models\PayoutAttempt;
use App\Models\ProviderWebhookReceipt;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;
use LogicException;
use SensitiveParameter;
use stdClass;
use Throwable;

final readonly class HandlePaystackWebhook
{
    // safeText accepts ASCII only, so these byte caps also fit the corresponding varchar lengths.
    private const int EVENT_TYPE_MAX_BYTES = 100;

    private const int PAYMENT_IDENTITY_MAX_BYTES = 255;

    public function __construct(
        private VerifyPaystackWebhookSignature $verifySignature,
        private RequestCashbackPayoutSupport $requestSupport,
    ) {}

    public function handle(
        #[SensitiveParameter] string $rawBody,
        #[SensitiveParameter] ?string $signature,
    ): void {
        if (DB::connection()->transactionLevel() > 0) {
            throw new LogicException('Paystack webhook handling cannot run inside an existing database transaction.');
        }

        $this->verifySignature->handle($rawBody, $signature);

        $recorded = DB::transaction(
            fn (): ?RecordedPaystackWebhook => $this->record($rawBody),
        );

        if ($recorded === null) {
            return;
        }

        try {
            $this->logRecordedWebhook($recorded);
        } catch (Throwable $exception) {
            $this->reportSafely($exception);
        }

        if ($recorded->supportRequest !== null) {
            $this->requestSupport->dispatch($recorded->supportRequest);
        }
    }

    private function record(
        #[SensitiveParameter] string $rawBody,
    ): ?RecordedPaystackWebhook {
        $receipt = ProviderWebhookReceipt::query()->createOrFirst(
            [
                'provider' => PaymentProvider::Paystack->value,
                'body_hash' => hash('sha256', $rawBody),
            ],
            [
                'result' => ProviderWebhookReceiptResult::Invalid->value,
                'received_at' => now(),
            ],
        );

        if (! $receipt->wasRecentlyCreated) {
            return null;
        }

        $payload = $this->decodeObject($rawBody);

        if ($payload === null) {
            return $this->finishReceipt($receipt, ProviderWebhookReceiptResult::Invalid);
        }

        $eventType = $this->safeText(
            $this->property($payload, 'event'),
            self::EVENT_TYPE_MAX_BYTES,
        );

        if ($eventType === null) {
            return $this->finishReceipt($receipt, ProviderWebhookReceiptResult::Invalid);
        }

        $receipt->event_type = $eventType;
        $data = $this->property($payload, 'data');

        if ($data instanceof stdClass) {
            $receipt->provider_reference = $this->safeText(
                $this->property($data, 'reference'),
                self::PAYMENT_IDENTITY_MAX_BYTES,
            );
        }

        $event = PaystackTransferEvent::tryFrom($eventType);

        if ($event === null) {
            return $this->finishReceipt($receipt, ProviderWebhookReceiptResult::Unsupported);
        }

        if (! $data instanceof stdClass) {
            return $this->finishReceipt($receipt, ProviderWebhookReceiptResult::Invalid);
        }

        $providerReference = $this->safeText(
            $this->property($data, 'reference'),
            self::PAYMENT_IDENTITY_MAX_BYTES,
        );
        $receipt->provider_reference = $providerReference;

        $callback = $this->callbackFrom($event, $data, $providerReference);

        if ($callback === null) {
            return $this->finishReceipt($receipt, ProviderWebhookReceiptResult::Invalid);
        }

        return $this->applyCallback($receipt, $callback);
    }

    private function callbackFrom(
        PaystackTransferEvent $event,
        #[SensitiveParameter] stdClass $data,
        #[SensitiveParameter] ?string $providerReference,
    ): ?PaystackTransferCallback {
        $recipient = $this->property($data, 'recipient');

        if (! $recipient instanceof stdClass) {
            return null;
        }

        $transferCode = $this->safeText(
            $this->property($data, 'transfer_code'),
            self::PAYMENT_IDENTITY_MAX_BYTES,
        );
        $recipientCode = $this->safeText(
            $this->property($recipient, 'recipient_code'),
            self::PAYMENT_IDENTITY_MAX_BYTES,
        );
        $amountMinor = $this->property($data, 'amount');

        if ($providerReference === null
            || $transferCode === null
            || $recipientCode === null
            || ! is_int($amountMinor)
            || $amountMinor <= 0
            || $this->property($data, 'currency') !== Currency::Ngn->value
            || $this->property($data, 'source') !== 'balance'
            || $this->property($data, 'status') !== $event->providerStatus()) {
            return null;
        }

        return new PaystackTransferCallback(
            event: $event,
            providerReference: $providerReference,
            transferCode: $transferCode,
            recipientCode: $recipientCode,
            amountMinor: $amountMinor,
        );
    }

    private function applyCallback(
        #[SensitiveParameter] ProviderWebhookReceipt $receipt,
        #[SensitiveParameter] PaystackTransferCallback $callback,
    ): RecordedPaystackWebhook {
        $reward = CashbackReward::query()
            ->where('provider_reference', $callback->providerReference)
            ->lockForUpdate()
            ->first();

        if ($reward === null) {
            return $this->finishReceipt($receipt, ProviderWebhookReceiptResult::NotFound);
        }

        $attempt = PayoutAttempt::query()
            ->where('cashback_reward_id', $reward->id)
            ->orderByDesc('attempt_number')
            ->lockForUpdate()
            ->first();

        if ($attempt === null) {
            return $this->finishReceipt($receipt, ProviderWebhookReceiptResult::NotFound);
        }

        if (! $this->matchesStoredPayment($reward, $attempt, $callback)) {
            return $this->finishReceipt($receipt, ProviderWebhookReceiptResult::Mismatch);
        }

        $receipt->payout_attempt_id = $attempt->id;
        $oldStatus = $attempt->status;
        $support = null;
        $result = ProviderWebhookReceiptResult::Unchanged;

        if ($this->rewardStatusMatchesAttemptStatus($reward, $attempt)
            && $this->mayTransition($attempt->status, $callback->event)) {
            $observedAt = now();
            [$errorCode, $errorMessage] = $this->safeErrorFor($callback->event);
            $targetStatus = $callback->event->attemptStatus();

            $attempt->fill([
                'status' => $targetStatus,
                'provider_transfer_code' => $callback->transferCode,
                'provider_error_code' => $errorCode?->value,
                'provider_error_message' => $errorMessage,
                'succeeded_at' => $targetStatus === PayoutAttemptStatus::Succeeded
                    ? ($attempt->succeeded_at ?? $observedAt)
                    : $attempt->succeeded_at,
                'reversed_at' => $targetStatus === PayoutAttemptStatus::Reversed
                    ? $observedAt
                    : null,
                'completed_at' => $attempt->completed_at ?? $observedAt,
            ]);

            $reward->fill([
                'status' => $targetStatus === PayoutAttemptStatus::Succeeded
                    ? CashbackRewardStatus::Paid
                    : CashbackRewardStatus::RequiresAttention,
                'last_error_code' => $errorCode?->value,
                'last_error_message' => $errorMessage,
                'paid_at' => $targetStatus === PayoutAttemptStatus::Succeeded
                    ? $observedAt
                    : null,
            ]);

            $support = $this->requestSupport->markWhileLocked($reward, $attempt);
            $attempt->save();
            $reward->save();
            $result = ProviderWebhookReceiptResult::Applied;
        }

        $receipt->result = $result;
        $receipt->save();

        return new RecordedPaystackWebhook(
            receiptId: $receipt->id,
            eventType: $receipt->event_type,
            result: $result,
            cashbackRewardId: $reward->id,
            payoutAttemptId: $attempt->id,
            oldAttemptStatus: $oldStatus,
            newAttemptStatus: $attempt->status,
            rewardStatus: $reward->status,
            correlationId: $reward->correlation_id,
            supportRequest: $support,
        );
    }

    private function matchesStoredPayment(
        #[SensitiveParameter] CashbackReward $reward,
        #[SensitiveParameter] PayoutAttempt $attempt,
        #[SensitiveParameter] PaystackTransferCallback $callback,
    ): bool {
        return $reward->provider === PaymentProvider::Paystack
            && $attempt->provider === PaymentProvider::Paystack
            && $reward->provider_reference === $callback->providerReference
            && $attempt->provider_reference === $callback->providerReference
            && $attempt->provider_recipient_code === $callback->recipientCode
            && $reward->amount_minor === $callback->amountMinor
            && $attempt->amount_minor === $callback->amountMinor
            && $reward->getRawOriginal('currency') === Currency::Ngn->value
            && $attempt->getRawOriginal('currency') === Currency::Ngn->value
            && ($attempt->provider_transfer_code === null
                || $attempt->provider_transfer_code === $callback->transferCode);
    }

    private function mayTransition(
        PayoutAttemptStatus $currentStatus,
        PaystackTransferEvent $event,
    ): bool {
        if (in_array($currentStatus, [
            PayoutAttemptStatus::Started,
            PayoutAttemptStatus::Ambiguous,
            PayoutAttemptStatus::Pending,
            PayoutAttemptStatus::OtpRequired,
        ], true)) {
            return true;
        }

        return $currentStatus === PayoutAttemptStatus::Succeeded
            && $event === PaystackTransferEvent::Reversed;
    }

    private function rewardStatusMatchesAttemptStatus(
        #[SensitiveParameter] CashbackReward $reward,
        #[SensitiveParameter] PayoutAttempt $attempt,
    ): bool {
        return match ($attempt->status) {
            PayoutAttemptStatus::Started,
            PayoutAttemptStatus::Ambiguous => $reward->status === CashbackRewardStatus::Processing,
            PayoutAttemptStatus::Pending => $reward->status === CashbackRewardStatus::Pending,
            PayoutAttemptStatus::Succeeded => $reward->status === CashbackRewardStatus::Paid,
            PayoutAttemptStatus::OtpRequired => $reward->status === CashbackRewardStatus::RequiresAttention,
            PayoutAttemptStatus::InsufficientFunds,
            PayoutAttemptStatus::RetryableRejection,
            PayoutAttemptStatus::PermanentRejection,
            PayoutAttemptStatus::Failed,
            PayoutAttemptStatus::Reversed => false,
        };
    }

    /** @return array{CashbackTransferErrorCode|null, string|null} */
    private function safeErrorFor(PaystackTransferEvent $event): array
    {
        return match ($event) {
            PaystackTransferEvent::Succeeded => [null, null],
            PaystackTransferEvent::Failed => [
                CashbackTransferErrorCode::TransferFailed,
                'Paystack reported that the transfer failed.',
            ],
            PaystackTransferEvent::Reversed => [
                CashbackTransferErrorCode::TransferReversed,
                'Paystack reported that the transfer was reversed.',
            ],
        };
    }

    private function finishReceipt(
        #[SensitiveParameter] ProviderWebhookReceipt $receipt,
        ProviderWebhookReceiptResult $result,
    ): RecordedPaystackWebhook {
        $receipt->result = $result;
        $receipt->save();

        return new RecordedPaystackWebhook(
            receiptId: $receipt->id,
            eventType: $receipt->event_type,
            result: $result,
            cashbackRewardId: null,
            payoutAttemptId: null,
            oldAttemptStatus: null,
            newAttemptStatus: null,
            rewardStatus: null,
            correlationId: null,
            supportRequest: null,
        );
    }

    private function logRecordedWebhook(RecordedPaystackWebhook $recorded): void
    {
        $context = [
            'receipt_id' => $recorded->receiptId,
            'event_type' => $recorded->eventType,
            'result' => $recorded->result->value,
            'cashback_reward_id' => $recorded->cashbackRewardId,
            'payout_attempt_id' => $recorded->payoutAttemptId,
            'old_attempt_status' => $recorded->oldAttemptStatus?->value,
            'new_attempt_status' => $recorded->newAttemptStatus?->value,
            'reward_status' => $recorded->rewardStatus?->value,
            'correlation_id' => $recorded->correlationId,
        ];

        Context::scope(function () use ($recorded, $context): void {
            match ($recorded->result) {
                ProviderWebhookReceiptResult::Applied => Log::info('paystack.webhook.recorded', $context),
                ProviderWebhookReceiptResult::Unchanged,
                ProviderWebhookReceiptResult::Unsupported => Log::debug('paystack.webhook.recorded', $context),
                ProviderWebhookReceiptResult::Invalid,
                ProviderWebhookReceiptResult::NotFound,
                ProviderWebhookReceiptResult::Mismatch => Log::warning('paystack.webhook.recorded', $context),
            };
        }, ['correlation_id' => $recorded->correlationId]);
    }

    private function decodeObject(
        #[SensitiveParameter] string $rawBody,
    ): ?stdClass {
        try {
            $payload = json_decode(
                $rawBody,
                associative: false,
                flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
        } catch (JsonException) {
            return null;
        }

        return $payload instanceof stdClass ? $payload : null;
    }

    private function property(#[SensitiveParameter] stdClass $object, string $name): mixed
    {
        return property_exists($object, $name) ? $object->{$name} : null;
    }

    private function safeText(#[SensitiveParameter] mixed $value, int $maxBytes): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $length = strlen($value);

        if ($length === 0
            || $length > $maxBytes
            || $value[0] === ' '
            || $value[$length - 1] === ' ') {
            return null;
        }

        for ($index = 0; $index < $length; $index++) {
            $byte = ord($value[$index]);

            if ($byte < 32 || $byte > 126) {
                return null;
            }
        }

        return $value;
    }

    private function reportSafely(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // A post-commit reporting failure cannot roll back or repair the durable callback.
        }
    }
}

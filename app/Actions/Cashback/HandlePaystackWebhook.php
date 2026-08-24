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
    private const string BODY_HASH_ALGORITHM = 'sha256';

    private const int FIRST_PRINTABLE_ASCII_BYTE = 32;

    private const int LAST_PRINTABLE_ASCII_BYTE = 126;

    private const string TRANSFER_SOURCE = 'balance';

    /*
     * readPrintableText() accepts ASCII only, so each character is one byte. These
     * limits match the database columns.
     */
    private const int EVENT_TYPE_MAX_BYTES = 100;

    private const int PAYMENT_IDENTITY_MAX_BYTES = 255;

    public function __construct(
        private VerifyPaystackWebhookSignature $verifyWebhookSignature,
        private RequestCashbackPayoutSupport $requestPayoutSupport,
    ) {}

    public function handle(
        #[SensitiveParameter] string $rawBody,
        #[SensitiveParameter] ?string $signature,
    ): void {
        /*
         * Do not run inside another transaction. Laravel would only add a savepoint,
         * so outer code could delay or undo these changes after we log or email.
         */
        if (DB::connection()->transactionLevel() > 0) {
            throw new LogicException('Paystack webhook handling cannot run inside an existing database transaction.');
        }

        $this->verifyWebhookSignature->handle($rawBody, $signature);

        $recordedWebhook = DB::transaction(
            function () use ($rawBody): ?RecordedPaystackWebhook {
                return $this->recordWebhook($rawBody);
            },
        );

        /*
         * A null result means this exact webhook was handled before.
         */
        if ($recordedWebhook === null) {
            return;
        }

        try {
            $this->logWebhookResult($recordedWebhook);
        } catch (Throwable $exception) {
            $this->reportLogFailure($exception);
        }

        if ($recordedWebhook->supportRequest !== null) {
            $this->requestPayoutSupport->dispatch($recordedWebhook->supportRequest);
        }
    }

    private function recordWebhook(
        #[SensitiveParameter] string $rawBody,
    ): ?RecordedPaystackWebhook {
        /*
         * The signature shows that the body was signed with our Paystack secret.
         * This hash spots the same body again, and the unique database key lets only
         * one request save its receipt.
         */
        $receipt = ProviderWebhookReceipt::query()->createOrFirst(
            [
                'provider' => PaymentProvider::Paystack->value,
                'body_hash' => hash(self::BODY_HASH_ALGORITHM, $rawBody),
            ],
            [
                'result' => ProviderWebhookReceiptResult::Invalid->value,
                'received_at' => now(),
            ],
        );

        if (! $receipt->wasRecentlyCreated) {
            return null;
        }

        $payload = $this->decodeJsonObject($rawBody);

        if ($payload === null) {
            return $this->saveReceiptResult($receipt, ProviderWebhookReceiptResult::Invalid);
        }

        $eventType = $this->readPrintableText(
            $this->readProperty($payload, 'event'),
            self::EVENT_TYPE_MAX_BYTES,
        );

        if ($eventType === null) {
            return $this->saveReceiptResult($receipt, ProviderWebhookReceiptResult::Invalid);
        }

        $receipt->event_type = $eventType;
        $transferData = $this->readProperty($payload, 'data');

        if ($transferData instanceof stdClass) {
            $receipt->provider_reference = $this->readPrintableText(
                $this->readProperty($transferData, 'reference'),
                self::PAYMENT_IDENTITY_MAX_BYTES,
            );
        }

        $event = PaystackTransferEvent::tryFrom($eventType);

        if ($event === null) {
            return $this->saveReceiptResult($receipt, ProviderWebhookReceiptResult::Unsupported);
        }

        if (! $transferData instanceof stdClass) {
            return $this->saveReceiptResult($receipt, ProviderWebhookReceiptResult::Invalid);
        }

        $providerReference = $this->readPrintableText(
            $this->readProperty($transferData, 'reference'),
            self::PAYMENT_IDENTITY_MAX_BYTES,
        );
        $receipt->provider_reference = $providerReference;

        $callback = $this->readTransferCallback($event, $transferData, $providerReference);

        if ($callback === null) {
            return $this->saveReceiptResult($receipt, ProviderWebhookReceiptResult::Invalid);
        }

        return $this->applyCallback($receipt, $callback);
    }

    private function readTransferCallback(
        PaystackTransferEvent $event,
        #[SensitiveParameter] stdClass $transferData,
        #[SensitiveParameter] ?string $providerReference,
    ): ?PaystackTransferCallback {
        $recipientData = $this->readProperty($transferData, 'recipient');

        if (! $recipientData instanceof stdClass) {
            return null;
        }

        $transferCode = $this->readPrintableText(
            $this->readProperty($transferData, 'transfer_code'),
            self::PAYMENT_IDENTITY_MAX_BYTES,
        );
        $recipientCode = $this->readPrintableText(
            $this->readProperty($recipientData, 'recipient_code'),
            self::PAYMENT_IDENTITY_MAX_BYTES,
        );
        $amountMinor = $this->readProperty($transferData, 'amount');

        if ($providerReference === null
            || $transferCode === null
            || $recipientCode === null
            || ! is_int($amountMinor)
            || $amountMinor <= 0
            || $this->readProperty($transferData, 'currency') !== Currency::Ngn->value
            || $this->readProperty($transferData, 'source') !== self::TRANSFER_SOURCE
            || $this->readProperty($transferData, 'status') !== $event->transferStatus()) {
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
        /*
         * Payment processing also locks the reward before the attempt. Using the
         * same order stops payment processing and the webhook from waiting on each
         * other.
         */
        $reward = CashbackReward::query()
            ->where('provider_reference', $callback->providerReference)
            ->lockForUpdate()
            ->first();

        if ($reward === null) {
            return $this->saveReceiptResult($receipt, ProviderWebhookReceiptResult::NotFound);
        }

        $attempt = PayoutAttempt::query()
            ->where('cashback_reward_id', $reward->id)
            ->orderByDesc('attempt_number')
            ->lockForUpdate()
            ->first();

        if ($attempt === null) {
            return $this->saveReceiptResult($receipt, ProviderWebhookReceiptResult::NotFound);
        }

        /*
         * A valid signature shows that the callback was signed with our Paystack
         * secret. It does not say which local payment it belongs to, so match every
         * payment detail.
         */
        if (! $this->callbackMatchesPayment($reward, $attempt, $callback)) {
            return $this->saveReceiptResult($receipt, ProviderWebhookReceiptResult::Mismatch);
        }

        $receipt->payout_attempt_id = $attempt->id;
        $oldAttemptStatus = $attempt->status;
        $supportRequest = null;
        $receiptResult = ProviderWebhookReceiptResult::Unchanged;

        /*
         * The reward and attempt must describe the same outcome. If they do not,
         * leave both unchanged rather than guess which row is right. Even when they
         * agree, do not let a late callback reopen a finished attempt.
         */
        if ($reward->status === CashbackRewardStatus::forAttempt($attempt->status)
            && $callback->event->canChangeAttemptFrom($attempt->status)) {
            $callbackTime = now();
            [$errorCode, $errorMessage] = $this->errorForEvent($callback->event);
            $newAttemptStatus = $callback->event->newAttemptStatus();

            $attempt->fill([
                'status' => $newAttemptStatus,
                'provider_transfer_code' => $callback->transferCode,
                'provider_error_code' => $errorCode?->value,
                'provider_error_message' => $errorMessage,
                'succeeded_at' => $newAttemptStatus === PayoutAttemptStatus::Succeeded
                    ? ($attempt->succeeded_at ?? $callbackTime)
                    : $attempt->succeeded_at,
                'reversed_at' => $newAttemptStatus === PayoutAttemptStatus::Reversed
                    ? $callbackTime
                    : null,
                'completed_at' => $attempt->completed_at ?? $callbackTime,
            ]);

            $reward->fill([
                'status' => $newAttemptStatus === PayoutAttemptStatus::Succeeded
                    ? CashbackRewardStatus::Paid
                    : CashbackRewardStatus::RequiresAttention,
                'last_error_code' => $errorCode?->value,
                'last_error_message' => $errorMessage,
                'paid_at' => $newAttemptStatus === PayoutAttemptStatus::Succeeded
                    ? $callbackTime
                    : null,
            ]);

            $supportRequest = $this->requestPayoutSupport->markWhileLocked($reward, $attempt);
            $attempt->save();
            $reward->save();
            $receiptResult = ProviderWebhookReceiptResult::Applied;
        }

        $receipt->result = $receiptResult;
        $receipt->save();

        return new RecordedPaystackWebhook(
            receiptId: $receipt->id,
            eventType: $receipt->event_type,
            result: $receiptResult,
            cashbackRewardId: $reward->id,
            payoutAttemptId: $attempt->id,
            oldAttemptStatus: $oldAttemptStatus,
            newAttemptStatus: $attempt->status,
            rewardStatus: $reward->status,
            correlationId: $reward->correlation_id,
            supportRequest: $supportRequest,
        );
    }

    private function callbackMatchesPayment(
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

    /** @return array{CashbackTransferErrorCode|null, string|null} */
    private function errorForEvent(PaystackTransferEvent $event): array
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

    private function saveReceiptResult(
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

    private function logWebhookResult(RecordedPaystackWebhook $recordedWebhook): void
    {
        $context = [
            'receipt_id' => $recordedWebhook->receiptId,
            'event_type' => $recordedWebhook->eventType,
            'result' => $recordedWebhook->result->value,
            'cashback_reward_id' => $recordedWebhook->cashbackRewardId,
            'payout_attempt_id' => $recordedWebhook->payoutAttemptId,
            'old_attempt_status' => $recordedWebhook->oldAttemptStatus?->value,
            'new_attempt_status' => $recordedWebhook->newAttemptStatus?->value,
            'reward_status' => $recordedWebhook->rewardStatus?->value,
            'correlation_id' => $recordedWebhook->correlationId,
        ];

        Context::scope(function () use ($recordedWebhook, $context): void {
            match ($recordedWebhook->result) {
                ProviderWebhookReceiptResult::Applied => Log::info('paystack.webhook.recorded', $context),
                ProviderWebhookReceiptResult::Unchanged,
                ProviderWebhookReceiptResult::Unsupported => Log::debug('paystack.webhook.recorded', $context),
                ProviderWebhookReceiptResult::Invalid,
                ProviderWebhookReceiptResult::NotFound,
                ProviderWebhookReceiptResult::Mismatch => Log::warning('paystack.webhook.recorded', $context),
            };
        }, ['correlation_id' => $recordedWebhook->correlationId]);
    }

    private function decodeJsonObject(
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

    private function readProperty(#[SensitiveParameter] stdClass $object, string $name): mixed
    {
        return property_exists($object, $name) ? $object->{$name} : null;
    }

    private function readPrintableText(#[SensitiveParameter] mixed $value, int $maxBytes): ?string
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

            /*
             * Printable ASCII starts with space (byte 32) and ends with tilde (byte
             * 126). Byte 127 is Delete, a control character, so it is not allowed.
             */
            if ($byte < self::FIRST_PRINTABLE_ASCII_BYTE
                || $byte > self::LAST_PRINTABLE_ASCII_BYTE) {
                return null;
            }
        }

        return $value;
    }

    private function reportLogFailure(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            /*
             * The callback is already saved, so a logging error cannot undo it or
             * stop the support message.
             */
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments;

use App\Contracts\Payments\CashbackTransferGateway;
use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payments\CashbackTransferResult;
use App\Data\Payments\CashbackTransferVerification;
use App\Data\Payments\TransferBalance;
use App\Enums\CashbackTransferErrorCode;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PaymentProviderFailure;
use App\Enums\PayoutAttemptStatus;
use App\Exceptions\Payments\PaymentProviderException;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final readonly class PaystackCashbackTransferGateway implements CashbackTransferGateway
{
    private const REFERENCE_PATTERN = '/\A[a-z0-9_-]{16,50}\z/';

    private const SOURCE = 'balance';

    private const RATE_LIMITED_PROVIDER_CODE = 'rate_limited';

    private const INSUFFICIENT_BALANCE_MESSAGE = 'your balance is not enough to fulfil this request';

    public function __construct(private PaystackClient $client) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Paystack;
    }

    public function availableBalance(Currency $currency): TransferBalance
    {
        $response = $this->client->get('balance');

        if ($response->successful() && $response->providerStatus() === null) {
            throw PaymentProviderException::malformedResponse();
        }

        if (! $response->successful() || $response->providerStatus() !== true) {
            throw PaymentProviderException::unavailable();
        }

        $data = $response->data();

        if (! is_array($data) || ! array_is_list($data)) {
            throw PaymentProviderException::malformedResponse();
        }

        $balances = [];

        foreach ($data as $entry) {
            if (! is_array($entry) || array_is_list($entry)) {
                throw PaymentProviderException::malformedResponse();
            }

            if (($entry['currency'] ?? null) === $currency->value) {
                $balances[] = $entry['balance'] ?? null;
            }
        }

        if (count($balances) !== 1 || ! is_int($balances[0]) || $balances[0] < 0) {
            throw PaymentProviderException::malformedResponse();
        }

        return new TransferBalance($balances[0], $currency);
    }

    public function initiateTransfer(CashbackTransferRequest $request): CashbackTransferResult
    {
        if (preg_match(self::REFERENCE_PATTERN, $request->providerReference) !== 1) {
            return new CashbackTransferResult(
                status: PayoutAttemptStatus::PermanentRejection,
                errorCode: CashbackTransferErrorCode::InvalidProviderReference,
                errorMessage: 'The stored transfer reference is invalid for Paystack.',
            );
        }

        if (! $this->client->isConfigured()) {
            return new CashbackTransferResult(
                status: PayoutAttemptStatus::PermanentRejection,
                errorCode: CashbackTransferErrorCode::ProviderUnavailable,
                errorMessage: 'Paystack is not configured for test transfers.',
            );
        }

        try {
            $response = $this->client->post('transfer', [
                'source' => self::SOURCE,
                'amount' => $request->amountMinor,
                'recipient' => $request->recipientCode,
                'reference' => $request->providerReference,
                'currency' => $request->currency->value,
            ]);
        } catch (PaymentProviderException $exception) {
            return $this->ambiguousTransportFailure($exception);
        }

        if ($response->successful() && $response->providerStatus() === true) {
            return $this->mapCreatedTransfer($response, $request);
        }

        if ($response->providerStatus() === true) {
            return $this->ambiguousResponse(
                $response,
                $this->transferCodeFrom($response),
                CashbackTransferErrorCode::ProviderInvalidResponse,
            );
        }

        return $this->mapRejectedTransfer($response);
    }

    public function verifyTransfer(string $providerReference): CashbackTransferVerification
    {
        if (preg_match(self::REFERENCE_PATTERN, $providerReference) !== 1) {
            throw PaymentProviderException::malformedResponse();
        }

        $response = $this->client->get('transfer/verify/'.rawurlencode($providerReference));

        if ($response->providerStatus() === false && $this->isTransferNotFound($response)) {
            if ($response->data() !== null) {
                return new CashbackTransferVerification(
                    $this->ambiguousResponse(
                        $response,
                        $this->transferCodeFrom($response),
                        CashbackTransferErrorCode::ProviderInvalidResponse,
                    ),
                );
            }

            return new CashbackTransferVerification(null);
        }

        if ($response->successful() && $response->providerStatus() === null) {
            throw PaymentProviderException::malformedResponse();
        }

        if (! $response->successful() || $response->providerStatus() !== true) {
            throw PaymentProviderException::unavailable();
        }

        return new CashbackTransferVerification(
            $this->mapCreatedTransfer($response, null, $providerReference),
        );
    }

    private function mapCreatedTransfer(
        #[SensitiveParameter] PaystackResponse $response,
        ?CashbackTransferRequest $request,
        ?string $providerReference = null,
    ): CashbackTransferResult {
        $data = $response->data();

        if (! is_array($data) || array_is_list($data)) {
            return $this->ambiguousResponse(
                $response,
                null,
                CashbackTransferErrorCode::ProviderInvalidResponse,
            );
        }

        /** @var array<string, mixed> $data */
        $reference = $this->nonEmptyString($data['reference'] ?? null);
        $expectedReference = $request instanceof CashbackTransferRequest
            ? $request->providerReference
            : $providerReference;
        $transferCode = $this->nonEmptyString($data['transfer_code'] ?? null);
        $status = $this->nonEmptyString($data['status'] ?? null);

        if ($expectedReference === null
            || $reference === null
            || ! hash_equals($expectedReference, $reference)
            || ! $this->hasValidTransferFacts($data, $request)) {
            return $this->ambiguousResponse(
                $response,
                $transferCode,
                CashbackTransferErrorCode::ProviderInvalidResponse,
            );
        }

        $mappedStatus = match ($status) {
            'success' => PayoutAttemptStatus::Succeeded,
            'pending', 'received' => PayoutAttemptStatus::Pending,
            'otp' => PayoutAttemptStatus::OtpRequired,
            'failed', 'abandoned', 'blocked', 'rejected' => PayoutAttemptStatus::Failed,
            'reversed' => PayoutAttemptStatus::Reversed,
            default => PayoutAttemptStatus::Ambiguous,
        };

        if ($mappedStatus !== PayoutAttemptStatus::Ambiguous && $transferCode === null) {
            return $this->ambiguousResponse(
                $response,
                null,
                CashbackTransferErrorCode::ProviderTransferIdentityMissing,
            );
        }

        if ($mappedStatus === PayoutAttemptStatus::Ambiguous) {
            return $this->ambiguousResponse(
                $response,
                $transferCode,
                CashbackTransferErrorCode::ProviderStatusUnknown,
            );
        }

        $errorCode = match ($mappedStatus) {
            PayoutAttemptStatus::OtpRequired => CashbackTransferErrorCode::OtpRequired,
            PayoutAttemptStatus::Failed => CashbackTransferErrorCode::TransferFailed,
            PayoutAttemptStatus::Reversed => CashbackTransferErrorCode::TransferReversed,
            default => null,
        };
        $errorMessage = match ($mappedStatus) {
            PayoutAttemptStatus::OtpRequired => 'Paystack requires transfer confirmation.',
            PayoutAttemptStatus::Failed => 'Paystack reported that the transfer failed.',
            PayoutAttemptStatus::Reversed => 'Paystack reported that the transfer was reversed.',
            default => null,
        };

        return new CashbackTransferResult(
            status: $mappedStatus,
            transferCode: $transferCode,
            httpStatus: $response->httpStatus,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            latencyMs: $response->latencyMs,
        );
    }

    /** @param array<string, mixed> $data */
    private function hasValidTransferFacts(
        #[SensitiveParameter] array $data,
        ?CashbackTransferRequest $request,
    ): bool {
        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? null;
        $source = $data['source'] ?? null;

        if (! is_int($amount)
            || $amount <= 0
            || $currency !== Currency::Ngn->value
            || $source !== self::SOURCE) {
            return false;
        }

        return $request === null || $amount === $request->amountMinor;
    }

    private function mapRejectedTransfer(
        #[SensitiveParameter] PaystackResponse $response,
    ): CashbackTransferResult {
        if ($response->data() !== null) {
            return $this->ambiguousResponse(
                $response,
                $this->transferCodeFrom($response),
                CashbackTransferErrorCode::ProviderInvalidResponse,
            );
        }

        $normalizedMessage = $this->normalizedMessage($response->message());

        if ($response->serverError() || $response->providerStatus() === null) {
            return $this->ambiguousResponse(
                $response,
                null,
                CashbackTransferErrorCode::ProviderUnavailable,
            );
        }

        if (! $response->successful() && ! $response->clientError()) {
            return $this->ambiguousResponse(
                $response,
                null,
                CashbackTransferErrorCode::ProviderInvalidResponse,
            );
        }

        if ($response->providerStatus() === false
            && in_array($response->httpStatus, [
                HttpResponse::HTTP_UNAUTHORIZED,
                HttpResponse::HTTP_FORBIDDEN,
            ], true)) {
            return new CashbackTransferResult(
                status: PayoutAttemptStatus::PermanentRejection,
                httpStatus: $response->httpStatus,
                errorCode: CashbackTransferErrorCode::ProviderUnavailable,
                errorMessage: 'Paystack rejected the configured test credential.',
                latencyMs: $response->latencyMs,
            );
        }

        if ($response->httpStatus === HttpResponse::HTTP_BAD_REQUEST
            && $response->providerStatus() === false
            && $this->isInsufficientBalanceMessage($normalizedMessage)) {
            return new CashbackTransferResult(
                status: PayoutAttemptStatus::InsufficientFunds,
                httpStatus: $response->httpStatus,
                errorCode: CashbackTransferErrorCode::InsufficientFunds,
                errorMessage: 'The Paystack balance is insufficient.',
                latencyMs: $response->latencyMs,
            );
        }

        if ($response->providerStatus() === false
            && ($response->httpStatus === HttpResponse::HTTP_TOO_MANY_REQUESTS
                || $response->providerCode() === self::RATE_LIMITED_PROVIDER_CODE)) {
            return new CashbackTransferResult(
                status: PayoutAttemptStatus::RetryableRejection,
                httpStatus: $response->httpStatus,
                errorCode: CashbackTransferErrorCode::RateLimited,
                errorMessage: 'Paystack rate limited the transfer request.',
                latencyMs: $response->latencyMs,
            );
        }

        if ($normalizedMessage !== null
            && str_contains($normalizedMessage, 'reference')
            && (str_contains($normalizedMessage, 'already') || str_contains($normalizedMessage, 'duplicate'))) {
            return $this->ambiguousResponse(
                $response,
                null,
                CashbackTransferErrorCode::DuplicateReference,
            );
        }

        return new CashbackTransferResult(
            status: PayoutAttemptStatus::PermanentRejection,
            httpStatus: $response->httpStatus,
            errorCode: CashbackTransferErrorCode::ProviderRejected,
            errorMessage: 'Paystack rejected the transfer before creation.',
            latencyMs: $response->latencyMs,
        );
    }

    private function ambiguousTransportFailure(PaymentProviderException $exception): CashbackTransferResult
    {
        [$code, $message] = match ($exception->failure) {
            PaymentProviderFailure::Timeout => [
                CashbackTransferErrorCode::ProviderTimeout,
                'Paystack did not return a conclusive transfer response in time.',
            ],
            PaymentProviderFailure::MalformedResponse => [
                CashbackTransferErrorCode::ProviderInvalidResponse,
                'Paystack returned an inconclusive transfer response.',
            ],
            PaymentProviderFailure::RecipientRejected => [
                CashbackTransferErrorCode::ProviderRejected,
                'Paystack did not return a conclusive transfer response.',
            ],
            PaymentProviderFailure::Unavailable => [
                CashbackTransferErrorCode::ProviderUnavailable,
                'Paystack was unavailable before a conclusive transfer response.',
            ],
        };

        return new CashbackTransferResult(
            status: PayoutAttemptStatus::Ambiguous,
            errorCode: $code,
            errorMessage: $message,
        );
    }

    private function ambiguousResponse(
        #[SensitiveParameter] PaystackResponse $response,
        ?string $transferCode,
        CashbackTransferErrorCode $errorCode,
    ): CashbackTransferResult {
        return new CashbackTransferResult(
            status: PayoutAttemptStatus::Ambiguous,
            transferCode: $transferCode,
            httpStatus: $response->httpStatus,
            errorCode: $errorCode,
            errorMessage: 'Paystack did not return a conclusive transfer result.',
            latencyMs: $response->latencyMs,
        );
    }

    private function isTransferNotFound(
        #[SensitiveParameter] PaystackResponse $response,
    ): bool {
        $message = $response->message();
        $code = $response->providerCode();

        return $response->httpStatus === HttpResponse::HTTP_NOT_FOUND
            && ($code === 'transfer_not_found'
                || ($message !== null && strtolower(rtrim($message, '.')) === 'transfer not found'));
    }

    private function transferCodeFrom(
        #[SensitiveParameter] PaystackResponse $response,
    ): ?string {
        $data = $response->data();

        if (! is_array($data) || array_is_list($data)) {
            return null;
        }

        return $this->nonEmptyString($data['transfer_code'] ?? null);
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizedMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $normalized = preg_replace('/[[:space:]]+/', ' ', strtolower(trim($message)));

        return $normalized === null ? null : rtrim($normalized, '.!');
    }

    private function isInsufficientBalanceMessage(?string $message): bool
    {
        return $message !== null
            && str_replace('fulfill', 'fulfil', $message) === self::INSUFFICIENT_BALANCE_MESSAGE;
    }
}

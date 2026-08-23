<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments;

use App\Contracts\Payments\TransferRecipientGateway;
use App\Data\Payments\CreatedTransferRecipient;
use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Exceptions\Payments\PaymentProviderException;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final readonly class PaystackTransferRecipientGateway implements TransferRecipientGateway
{
    private const RECIPIENT_TYPE = 'nuban';

    public function __construct(private PaystackClient $client) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Paystack;
    }

    public function createRecipient(
        #[SensitiveParameter] RegisterPayoutAccountInput $input,
    ): CreatedTransferRecipient {
        $resolution = $this->client->get('bank/resolve', [
            'account_number' => $input->accountNumber,
            'bank_code' => $input->bankCode,
        ]);
        $resolved = $this->successfulData($resolution);
        $accountName = $this->requiredString($resolved, 'account_name');

        if ($this->requiredString($resolved, 'account_number') !== $input->accountNumber) {
            throw PaymentProviderException::malformedResponse();
        }

        $recipientResponse = $this->client->post('transferrecipient', [
            'type' => self::RECIPIENT_TYPE,
            'name' => $accountName,
            'account_number' => $input->accountNumber,
            'bank_code' => $input->bankCode,
            'currency' => Currency::Ngn->value,
        ]);
        $recipient = $this->successfulData($recipientResponse);
        $details = $recipient['details'] ?? null;

        if (! is_array($details) || array_is_list($details)) {
            throw PaymentProviderException::malformedResponse();
        }

        /** @var array<string, mixed> $details */
        if (($recipient['active'] ?? null) !== true
            || $this->requiredString($recipient, 'name') !== $accountName
            || $this->requiredString($recipient, 'type') !== self::RECIPIENT_TYPE
            || $this->requiredString($recipient, 'currency') !== Currency::Ngn->value
            || $this->requiredString($details, 'account_number') !== $input->accountNumber
            || $this->requiredString($details, 'bank_code') !== $input->bankCode) {
            throw PaymentProviderException::malformedResponse();
        }

        return new CreatedTransferRecipient(
            provider: PaymentProvider::Paystack,
            recipientCode: $this->requiredString($recipient, 'recipient_code'),
            accountName: $accountName,
            bankName: $this->requiredString($details, 'bank_name'),
            bankCode: $input->bankCode,
            accountLastFour: substr($input->accountNumber, -4),
            currency: Currency::Ngn,
        );
    }

    /** @return array<string, mixed> */
    private function successfulData(
        #[SensitiveParameter] PaystackResponse $response,
    ): array {
        if (! $response->successful() || $response->providerStatus() !== true) {
            $this->throwFailure($response);
        }

        $data = $response->data();

        if (! is_array($data) || array_is_list($data)) {
            throw PaymentProviderException::malformedResponse();
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function throwFailure(
        #[SensitiveParameter] PaystackResponse $response,
    ): never {
        if ($response->httpStatus === HttpResponse::HTTP_UNAUTHORIZED
            || $response->httpStatus === HttpResponse::HTTP_FORBIDDEN
            || $response->httpStatus === HttpResponse::HTTP_TOO_MANY_REQUESTS
            || $response->serverError()) {
            throw PaymentProviderException::unavailable();
        }

        if ($response->providerStatus() === false
            && ($response->successful() || $response->clientError())) {
            throw PaymentProviderException::recipientRejected();
        }

        throw PaymentProviderException::malformedResponse();
    }

    /** @param array<string, mixed> $values */
    private function requiredString(
        #[SensitiveParameter] array $values,
        string $key,
    ): string {
        $value = $values[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw PaymentProviderException::malformedResponse();
        }

        return trim($value);
    }
}

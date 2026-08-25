<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Payments\TransferRecipientGateway;
use App\Data\Payments\CreatedTransferRecipient;
use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Enums\PaymentProvider;
use App\Enums\PaymentProviderFailure;
use App\Exceptions\Payments\PaymentProviderException;
use SensitiveParameter;

final readonly class FailingRecipientGateway implements TransferRecipientGateway
{
    public function __construct(private PaymentProviderFailure $failure) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Fake;
    }

    public function createRecipient(
        #[SensitiveParameter] RegisterPayoutAccountInput $input,
    ): CreatedTransferRecipient {
        throw match ($this->failure) {
            PaymentProviderFailure::RecipientRejected => PaymentProviderException::recipientRejected(),
            PaymentProviderFailure::Unavailable => PaymentProviderException::unavailable(),
            PaymentProviderFailure::MalformedResponse => PaymentProviderException::malformedResponse(),
            PaymentProviderFailure::Timeout => PaymentProviderException::timeout(),
        };
    }
}

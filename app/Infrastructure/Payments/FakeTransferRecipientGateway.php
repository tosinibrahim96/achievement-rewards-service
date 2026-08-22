<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments;

use App\Contracts\Payments\TransferRecipientGateway;
use App\Data\Payments\CreatedTransferRecipient;
use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Exceptions\Payments\PaymentProviderException;
use Illuminate\Container\Attributes\Config;
use SensitiveParameter;

final readonly class FakeTransferRecipientGateway implements TransferRecipientGateway
{
    public function __construct(
        #[Config('payments.fake.payout_account_scenario', 'success')] private string $scenario,
        #[Config('app.key')] private ?string $applicationKey,
    ) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Fake;
    }

    public function createRecipient(
        #[SensitiveParameter] RegisterPayoutAccountInput $input,
    ): CreatedTransferRecipient {
        if ($this->scenario === 'rejected') {
            throw PaymentProviderException::recipientRejected();
        }

        if ($this->scenario !== 'success' || $this->applicationKey === null || $this->applicationKey === '') {
            throw PaymentProviderException::unavailable();
        }

        $identity = hash_hmac(
            'sha256',
            $input->bankCode.'|'.$input->accountNumber,
            $this->applicationKey,
        );

        return new CreatedTransferRecipient(
            provider: PaymentProvider::Fake,
            recipientCode: 'RCP_FAKE_'.$identity,
            accountName: 'Demo Customer',
            bankName: 'Demo Bank',
            bankCode: $input->bankCode,
            accountLastFour: substr($input->accountNumber, -4),
            currency: Currency::Ngn,
        );
    }
}

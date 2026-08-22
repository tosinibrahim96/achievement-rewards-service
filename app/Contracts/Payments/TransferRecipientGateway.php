<?php

declare(strict_types=1);

namespace App\Contracts\Payments;

use App\Data\Payments\CreatedTransferRecipient;
use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Enums\PaymentProvider;
use SensitiveParameter;

interface TransferRecipientGateway
{
    public function provider(): PaymentProvider;

    public function createRecipient(
        #[SensitiveParameter] RegisterPayoutAccountInput $input,
    ): CreatedTransferRecipient;
}

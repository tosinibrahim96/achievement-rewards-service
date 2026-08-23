<?php

declare(strict_types=1);

namespace App\Exceptions\Webhooks;

use RuntimeException;

final class WebhookVerificationUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Paystack webhook verification is unavailable.');
    }
}

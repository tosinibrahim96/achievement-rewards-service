<?php

declare(strict_types=1);

namespace App\Exceptions\Webhooks;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class WebhookPayloadTooLargeException extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('The Paystack webhook payload is too large.');
    }
}

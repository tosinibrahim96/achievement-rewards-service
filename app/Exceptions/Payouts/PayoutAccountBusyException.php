<?php

declare(strict_types=1);

namespace App\Exceptions\Payouts;

use RuntimeException;
use Throwable;

final class PayoutAccountBusyException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Another payout account update is already in progress.', previous: $previous);
    }
}

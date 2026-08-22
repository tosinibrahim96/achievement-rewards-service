<?php

declare(strict_types=1);

namespace App\Exceptions\Payouts;

use RuntimeException;
use Throwable;

final class PayoutAccountConflictException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The payout account conflicts with an existing destination.', previous: $previous);
    }
}

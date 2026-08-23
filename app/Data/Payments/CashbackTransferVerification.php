<?php

declare(strict_types=1);

namespace App\Data\Payments;

final readonly class CashbackTransferVerification
{
    public function __construct(public ?CashbackTransferResult $result) {}
}

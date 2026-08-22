<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentProvider: string
{
    case Fake = 'fake';
    case Paystack = 'paystack';
}

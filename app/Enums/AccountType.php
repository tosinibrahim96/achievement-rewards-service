<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountType: string
{
    case Customer = 'customer';
    case System = 'system';
}

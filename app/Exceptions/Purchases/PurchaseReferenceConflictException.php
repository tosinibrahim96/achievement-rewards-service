<?php

declare(strict_types=1);

namespace App\Exceptions\Purchases;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class PurchaseReferenceConflictException extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('The external reference is already associated with a different purchase.');
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class InvalidCredentialsException extends RuntimeException implements ShouldntReport
{
    /*
     * Wrong login details are normal client errors, so Laravel should not report them.
     */
}

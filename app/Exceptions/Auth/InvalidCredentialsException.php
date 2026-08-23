<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class InvalidCredentialsException extends RuntimeException implements ShouldntReport
{
    /*
     * Invalid login attempts are expected client errors, not reportable server failures.
     */
}

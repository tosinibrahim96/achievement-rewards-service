<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AccountType;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCustomerAccount
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->account_type !== AccountType::Customer) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}

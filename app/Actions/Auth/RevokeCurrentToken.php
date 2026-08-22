<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

final readonly class RevokeCurrentToken
{
    public function handle(User $user): void
    {
        /** @var PersonalAccessToken|TransientToken|null $token */
        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            throw new AuthenticationException(guards: ['sanctum']);
        }

        $token->delete();
    }
}

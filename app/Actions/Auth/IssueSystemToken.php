<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\AuthenticationResult;
use App\Enums\AccountType;
use App\Enums\TokenAbility;
use App\Models\User;
use InvalidArgumentException;

final readonly class IssueSystemToken
{
    public function handle(User $user, string $deviceName): AuthenticationResult
    {
        if ($user->account_type !== AccountType::System) {
            throw new InvalidArgumentException('System tokens can only be issued to system accounts.');
        }

        $abilities = TokenAbility::systemValues();
        $token = $user->createToken($deviceName, $abilities);

        return new AuthenticationResult(
            user: $user,
            plainTextToken: $token->plainTextToken,
            tokenType: 'Bearer',
            abilities: $abilities,
        );
    }
}

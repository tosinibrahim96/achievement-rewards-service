<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\AuthenticationResult;
use App\Enums\AccountType;
use App\Enums\TokenAbility;
use App\Models\User;
use InvalidArgumentException;

final readonly class IssueCustomerToken
{
    public function handle(User $user, string $deviceName): AuthenticationResult
    {
        if ($user->account_type !== AccountType::Customer) {
            throw new InvalidArgumentException('Customer tokens can only be issued to customer accounts.');
        }

        $abilities = TokenAbility::customerValues();
        $token = $user->createToken($deviceName, $abilities);

        return new AuthenticationResult(
            user: $user,
            plainTextToken: $token->plainTextToken,
            tokenType: 'Bearer',
            abilities: $abilities,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Data\Auth;

use App\Models\User;

final readonly class AuthenticationResult
{
    /**
     * @param  list<string>  $abilities
     */
    public function __construct(
        public User $user,
        public string $plainTextToken,
        public string $tokenType,
        public array $abilities,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\AuthenticationResult;
use App\Data\Auth\LoginInput;
use App\Enums\AccountType;
use App\Exceptions\Auth\InvalidCredentialsException;
use SensitiveParameter;

final readonly class LoginSystem
{
    public function __construct(
        private AuthenticateUser $authenticateUser,
        private IssueSystemToken $issueSystemToken,
    ) {}

    /** @throws InvalidCredentialsException */
    public function handle(#[SensitiveParameter] LoginInput $input): AuthenticationResult
    {
        $user = $this->authenticateUser->handle($input, AccountType::System);

        return $this->issueSystemToken->handle($user, $input->deviceName);
    }
}

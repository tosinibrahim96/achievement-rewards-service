<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\AuthenticationResult;
use App\Data\Auth\LoginCustomerInput;
use App\Enums\AccountType;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

final readonly class LoginCustomer
{
    public function __construct(
        private IssueCustomerToken $issueCustomerToken,
    ) {}

    /** @throws InvalidCredentialsException */
    public function handle(#[SensitiveParameter] LoginCustomerInput $input): AuthenticationResult
    {
        $user = User::query()->where('email', $input->email)->first();

        if (! $user instanceof User
            || $user->account_type !== AccountType::Customer
            || ! Hash::check($input->password, $user->password)) {
            throw new InvalidCredentialsException;
        }

        return $this->issueCustomerToken->handle($user, $input->deviceName);
    }
}

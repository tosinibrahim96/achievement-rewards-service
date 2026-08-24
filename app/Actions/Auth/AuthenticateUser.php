<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\LoginInput;
use App\Enums\AccountType;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Timebox;
use SensitiveParameter;

final readonly class AuthenticateUser
{
    private const int FAILED_LOGIN_MINIMUM_MICROSECONDS = 250_000;

    public function __construct(
        private Timebox $timebox,
    ) {}

    /** @throws InvalidCredentialsException */
    public function handle(#[SensitiveParameter] LoginInput $input, AccountType $accountType): User
    {
        $this->timebox->dontReturnEarly();

        return $this->timebox->call(function (Timebox $timebox) use ($input, $accountType): User {
            $user = User::query()->where('email', $input->email)->first();

            if (! $user instanceof User
                || $user->account_type !== $accountType
                || ! Hash::check($input->password, $user->password)) {
                throw new InvalidCredentialsException;
            }

            $timebox->returnEarly();

            return $user;
        }, self::FAILED_LOGIN_MINIMUM_MICROSECONDS);
    }
}

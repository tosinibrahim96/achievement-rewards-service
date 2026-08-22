<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\AuthenticationResult;
use App\Data\Auth\RegisterCustomerInput;
use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

final readonly class RegisterCustomer
{
    public function __construct(
        private IssueCustomerToken $issueCustomerToken,
    ) {}

    public function handle(#[SensitiveParameter] RegisterCustomerInput $input): AuthenticationResult
    {
        return DB::transaction(function () use ($input): AuthenticationResult {
            $user = User::query()->create([
                'name' => $input->name,
                'email' => $input->email,
                'password' => Hash::make($input->password),
                'account_type' => AccountType::Customer,
            ]);

            return $this->issueCustomerToken->handle($user, $input->deviceName);
        });
    }
}

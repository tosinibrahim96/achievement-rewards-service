<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Models\PayoutAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PayoutAccount> */
class PayoutAccountFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => PaymentProvider::Fake,
            'provider_recipient_code' => 'RCP_FAKE_'.Str::lower((string) Str::ulid()),
            'bank_code' => '057',
            'bank_name' => 'Demo Bank',
            'account_name' => 'Demo Customer',
            'account_last_four' => fake()->numerify('####'),
            'currency' => Currency::Ngn,
            'verified_at' => now(),
        ];
    }
}

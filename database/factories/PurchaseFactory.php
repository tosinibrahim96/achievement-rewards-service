<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Currency;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Purchase> */
class PurchaseFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'external_reference' => 'ORDER-'.Str::ulid(),
            'amount_minor' => fake()->numberBetween(1, 10_000_000),
            'currency' => Currency::Ngn,
            'completed_at' => now(),
            'correlation_id' => (string) Str::ulid(),
        ];
    }
}

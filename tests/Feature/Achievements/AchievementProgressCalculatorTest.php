<?php

declare(strict_types=1);

use App\Domain\Achievements\LifetimeSpendProgressCalculator;
use App\Domain\Achievements\PurchaseCountProgressCalculator;
use App\Enums\Currency;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

it('reports zero progress before a completed purchase exists', function (): void {
    $user = User::factory()->create();

    expect(app(PurchaseCountProgressCalculator::class)->progressFor($user))->toBe(0)
        ->and(app(LifetimeSpendProgressCalculator::class)->progressFor($user))->toBe(0);
});

it('counts the persisted completed-purchase facts for one user', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    Purchase::factory()->count(3)->for($user)->create();
    Purchase::factory()->count(2)->for($otherUser)->create();

    expect(app(PurchaseCountProgressCalculator::class)->progressFor($user))->toBe(3);
});

it('sums only integer NGN minor units for one user', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    Purchase::factory()->for($user)->create(['amount_minor' => 125_001]);
    Purchase::factory()->for($user)->create(['amount_minor' => 374_999]);
    Purchase::factory()->for($otherUser)->create(['amount_minor' => 9_000_000]);

    expect(app(LifetimeSpendProgressCalculator::class)->progressFor($user))->toBe(500_000);
});

it('defensively excludes non-NGN rows even if legacy data predates the database constraint', function (): void {
    $user = User::factory()->create();
    $now = now();

    DB::statement('ALTER TABLE purchases DROP CONSTRAINT purchases_currency_check');

    try {
        DB::table('purchases')->insert([
            [
                'user_id' => $user->id,
                'external_reference' => 'LEGACY-NGN',
                'amount_minor' => 200_000,
                'currency' => Currency::Ngn->value,
                'completed_at' => $now,
                'correlation_id' => (string) Str::ulid(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => $user->id,
                'external_reference' => 'LEGACY-USD',
                'amount_minor' => 9_999_999,
                'currency' => 'USD',
                'completed_at' => $now,
                'correlation_id' => (string) Str::ulid(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        expect(app(LifetimeSpendProgressCalculator::class)->progressFor($user))->toBe(200_000);
    } finally {
        DB::table('purchases')->where('currency', 'USD')->delete();
        DB::statement("ALTER TABLE purchases ADD CONSTRAINT purchases_currency_check CHECK (currency = 'NGN')");
    }
});

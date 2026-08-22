<?php

declare(strict_types=1);

use App\Domain\Achievements\LifetimeSpendProgressCalculator;
use App\Domain\Achievements\PurchaseCountProgressCalculator;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;

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

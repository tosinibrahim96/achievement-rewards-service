<?php

declare(strict_types=1);

use App\Actions\Purchases\RecordPurchase;
use App\Data\Purchases\RecordPurchaseInput;
use App\Domain\Money\Money;
use App\Enums\Currency;
use App\Models\Purchase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

it('rejects a non-positive completed purchase at the application boundary', function (): void {
    $user = User::factory()->create();
    $input = new RecordPurchaseInput(
        userId: $user->id,
        externalReference: 'ORDER-ZERO',
        amount: new Money(0, Currency::Ngn),
        completedAt: CarbonImmutable::parse('2026-08-21T14:30:00Z'),
    );

    expect(fn () => app(RecordPurchase::class)->handle($input))
        ->toThrow(InvalidArgumentException::class, 'A completed purchase amount must be positive.')
        ->and(Purchase::query()->count())->toBe(0);
});

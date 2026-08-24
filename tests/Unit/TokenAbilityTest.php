<?php

declare(strict_types=1);

use App\Enums\TokenAbility;

it('keeps customer abilities least privileged', function (): void {
    expect(TokenAbility::customerValues())
        ->toBe([
            TokenAbility::AchievementsRead->value,
            TokenAbility::PayoutAccountsWrite->value,
            TokenAbility::CashbackRewardsRead->value,
        ])
        ->not->toContain(TokenAbility::PurchasesWrite->value);
});

it('gives system login only the purchase ingestion ability', function (): void {
    expect(TokenAbility::systemValues())->toBe([
        TokenAbility::PurchasesWrite->value,
    ]);
});

<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Enums\Currency;
use InvalidArgumentException;

it('represents non-negative integer minor units with an explicit currency', function (): void {
    $zero = new Money(0, Currency::Ngn);
    $positive = new Money(30_000, Currency::Ngn);

    expect($zero->isPositive())->toBeFalse()
        ->and($positive->amountMinor)->toBe(30_000)
        ->and($positive->currency)->toBe(Currency::Ngn)
        ->and($positive->isPositive())->toBeTrue();
});

it('rejects negative monetary values', function (): void {
    expect(fn (): Money => new Money(-1, Currency::Ngn))
        ->toThrow(InvalidArgumentException::class, 'A monetary amount cannot be negative.');
});

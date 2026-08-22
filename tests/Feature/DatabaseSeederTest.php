<?php

declare(strict_types=1);

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can seed the default user more than once without creating duplicates', function (): void {
    $this->seed();
    $this->seed();

    $user = User::query()->where('email', 'test@example.com')->sole();

    expect(User::query()->where('email', 'test@example.com')->count())->toBe(1)
        ->and($user->account_type)->toBe(AccountType::Customer);
});

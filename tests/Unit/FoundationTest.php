<?php

declare(strict_types=1);

use App\Enums\AccountType;
use App\Models\User;

it('uses the expected runtime version', function (): void {
    expect(PHP_VERSION_ID)->toBeGreaterThanOrEqual(80400);
});

it('keeps sensitive user attributes hidden and securely cast', function (): void {
    $user = new User;

    expect($user->getHidden())->toContain('password', 'remember_token')
        ->and($user->getCasts())->toMatchArray([
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'account_type' => AccountType::class,
        ]);
});

<?php

declare(strict_types=1);

use App\Actions\Auth\IssueCustomerToken;
use App\Actions\Auth\IssueSystemToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;

uses(RefreshDatabase::class);

it('refuses to issue customer abilities to a system identity', function (): void {
    $system = User::factory()->system()->create();

    expect(fn () => app(IssueCustomerToken::class)->handle($system, 'invalid'))
        ->toThrow(InvalidArgumentException::class, 'Customer tokens can only be issued to customer accounts.');
});

it('refuses to issue system abilities to a customer identity', function (): void {
    $customer = User::factory()->create();

    expect(fn () => app(IssueSystemToken::class)->handle($customer, 'invalid'))
        ->toThrow(InvalidArgumentException::class, 'System tokens can only be issued to system accounts.');
});

it('restricts Sanctum authentication to the users provider', function (): void {
    expect(config('auth.guards.sanctum'))->toBe([
        'driver' => 'sanctum',
        'provider' => 'users',
    ]);
});

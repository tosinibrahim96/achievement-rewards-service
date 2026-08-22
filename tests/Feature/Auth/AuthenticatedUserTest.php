<?php

declare(strict_types=1);

use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the authenticated customer without sensitive attributes', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('test', TokenAbility::customerValues());

    $this->withToken($token->plainTextToken)
        ->getJson('/api/me')
        ->assertOk()
        ->assertExactJson([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'account_type' => $user->account_type->value,
        ]);
});

it('requires authentication to read the current user', function (): void {
    $this->getJson('/api/me')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer')
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('code', 'unauthenticated');
});

it('allows the users provider to authenticate a system User identity', function (): void {
    $system = User::factory()->system()->create();
    $token = $system->createToken('checkout', [TokenAbility::PurchasesWrite->value]);

    $this->withToken($token->plainTextToken)
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('id', $system->id)
        ->assertJsonPath('account_type', 'system');
});

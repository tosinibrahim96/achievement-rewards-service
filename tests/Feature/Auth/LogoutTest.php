<?php

declare(strict_types=1);

use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

it('revokes only the bearer token used for logout', function (): void {
    $user = User::factory()->create();
    $currentToken = $user->createToken('current', TokenAbility::customerValues());
    $otherToken = $user->createToken('other', TokenAbility::customerValues());

    $this->withToken($currentToken->plainTextToken)
        ->postJson('/api/auth/logout')
        ->assertNoContent();

    $this->assertDatabaseMissing('personal_access_tokens', [
        'id' => $currentToken->accessToken->id,
    ])->assertDatabaseHas('personal_access_tokens', [
        'id' => $otherToken->accessToken->id,
    ]);

    Auth::forgetGuards();

    $this->withToken($currentToken->plainTextToken)
        ->getJson('/api/me')
        ->assertUnauthorized();

    Auth::forgetGuards();

    $this->withToken($otherToken->plainTextToken)
        ->getJson('/api/me')
        ->assertOk();
});

it('requires authentication to log out', function (): void {
    $this->postJson('/api/auth/logout')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer')
        ->assertJsonPath('code', 'unauthenticated');
});

it('rejects transient authentication because there is no persisted bearer token to revoke', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    $this->postJson('/api/auth/logout')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer')
        ->assertJsonPath('code', 'unauthenticated');
});

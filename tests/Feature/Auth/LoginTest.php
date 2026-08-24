<?php

declare(strict_types=1);

use App\Enums\TokenAbility;
use App\Http\Middleware\AssignRequestId;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Sleep::fake();
});

afterEach(function (): void {
    Sleep::fake(false);
});

it('authenticates a customer and names the least-privilege token', function (): void {
    $user = User::factory()->create([
        'email' => 'customer@example.com',
        'password' => 'secure-password',
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => '  CUSTOMER@EXAMPLE.COM ',
        'password' => 'secure-password',
        'device_name' => 'Ibrahim MacBook',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.email', 'customer@example.com')
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('abilities', TokenAbility::customerValues())
        ->assertJsonMissingPath('data');

    expect($response->json('token'))->toBeString()
        ->and($user->tokens()->sole()->name)->toBe('Ibrahim MacBook')
        ->and($user->tokens()->sole()->abilities)->toBe(TokenAbility::customerValues());
    Sleep::assertNeverSlept();
});

it('returns the same error for an unknown email and an incorrect password', function (array $credentials): void {
    User::factory()->create([
        'email' => 'customer@example.com',
        'password' => 'secure-password',
    ]);

    $this->postJson('/api/auth/login', $credentials)
        ->assertUnauthorized()
        ->assertHeaderMissing('WWW-Authenticate')
        ->assertExactJson([
            'code' => 'invalid_credentials',
            'message' => 'The provided credentials are incorrect.',
        ]);

    Sleep::assertSleptTimes(1);
})->with([
    'unknown email' => [[
        'email' => 'unknown@example.com',
        'password' => 'secure-password',
    ]],
    'incorrect password' => [[
        'email' => 'customer@example.com',
        'password' => 'incorrect-password',
    ]],
]);

it('does not authenticate a system identity through the public customer login', function (): void {
    $system = User::factory()->system()->create([
        'email' => 'checkout-system@example.com',
        'password' => 'secure-password',
    ]);

    $this->postJson('/api/auth/login', [
        'email' => $system->email,
        'password' => 'secure-password',
    ])
        ->assertUnauthorized()
        ->assertHeaderMissing('WWW-Authenticate')
        ->assertExactJson([
            'code' => 'invalid_credentials',
            'message' => 'The provided credentials are incorrect.',
        ]);

    expect($system->tokens()->exists())->toBeFalse();
    Sleep::assertSleptTimes(1);
});

it('rate limits repeated login attempts by normalized email and ip address', function (): void {
    User::factory()->create([
        'email' => 'customer@example.com',
        'password' => 'secure-password',
    ]);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/auth/login', [
            'email' => $attempt % 2 === 0 ? ' CUSTOMER@EXAMPLE.COM ' : 'customer@example.com',
            'password' => 'incorrect-password',
        ])->assertUnauthorized();
    }

    $response = $this->postJson('/api/auth/login', [
        'email' => 'customer@example.com',
        'password' => 'incorrect-password',
    ]);

    $response
        ->assertTooManyRequests()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('code', 'rate_limit_exceeded')
        ->assertJsonStructure(['code', 'message'])
        ->assertJsonMissingPath('status')
        ->assertJsonMissingPath('request_id');

    expect($response->headers->get('Retry-After'))->not->toBeNull()
        ->and($response->headers->get(AssignRequestId::HEADER))->toBeString();
    Sleep::assertSleptTimes(5);
});

it('validates login input before attempting authentication', function (): void {
    $this->postJson('/api/auth/login', [
        'email' => 'not-an-email',
        'password' => '',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
    Sleep::assertNeverSlept();
});

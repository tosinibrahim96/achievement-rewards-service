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

it('authenticates a system identity with only the purchase ingestion ability', function (): void {
    $system = User::factory()->system()->create([
        'email' => 'purchase-system@example.com',
        'password' => 'secure-password',
    ]);

    $response = $this->postJson('/api/auth/system/login', [
        'email' => '  PURCHASE-SYSTEM@EXAMPLE.COM ',
        'password' => 'secure-password',
        'device_name' => 'README demo',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('user.id', $system->id)
        ->assertJsonPath('user.email', 'purchase-system@example.com')
        ->assertJsonPath('user.account_type', 'system')
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('abilities', TokenAbility::systemValues())
        ->assertJsonMissingPath('data');

    expect($response->json('token'))->toBeString()
        ->and($system->tokens()->sole()->name)->toBe('README demo')
        ->and($system->tokens()->sole()->abilities)->toBe(TokenAbility::systemValues());

    $this->withToken($response->json('token'))
        ->getJson('/api/me/cashback-rewards')
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
    Sleep::assertNeverSlept();
});

it('restores the failed-login timebox after a successful login', function (): void {
    User::factory()->system()->create([
        'email' => 'purchase-system@example.com',
        'password' => 'secure-password',
    ]);

    $this->postJson('/api/auth/system/login', [
        'email' => 'purchase-system@example.com',
        'password' => 'secure-password',
    ])->assertOk();

    Sleep::assertNeverSlept();

    $this->postJson('/api/auth/system/login', [
        'email' => 'unknown-system@example.com',
        'password' => 'secure-password',
    ])->assertUnauthorized();

    Sleep::assertSleptTimes(1);
});

it('uses one generic error for unknown credentials wrong passwords and customer identities', function (): void {
    $system = User::factory()->system()->create([
        'email' => 'known-system@example.com',
        'password' => 'secure-password',
    ]);
    $customer = User::factory()->create([
        'email' => 'customer@example.com',
        'password' => 'secure-password',
    ]);
    $attempts = [
        ['email' => 'unknown-system@example.com', 'password' => 'secure-password'],
        ['email' => $system->email, 'password' => 'incorrect-password'],
        ['email' => $customer->email, 'password' => 'secure-password'],
    ];

    foreach ($attempts as $credentials) {
        $this->postJson('/api/auth/system/login', $credentials)
            ->assertUnauthorized()
            ->assertHeaderMissing('WWW-Authenticate')
            ->assertExactJson([
                'code' => 'invalid_credentials',
                'message' => 'The provided credentials are incorrect.',
            ]);
    }

    expect($system->tokens()->exists())->toBeFalse()
        ->and($customer->tokens()->exists())->toBeFalse();
    Sleep::assertSleptTimes(3);
});

it('shares the normalized email and ip rate limit with customer login', function (): void {
    $paths = [
        '/api/auth/login',
        '/api/auth/system/login',
        '/api/auth/login',
        '/api/auth/system/login',
        '/api/auth/login',
    ];

    foreach ($paths as $index => $path) {
        $this->postJson($path, [
            'email' => $index % 2 === 0
                ? ' SHARED-LIMIT@EXAMPLE.COM '
                : 'shared-limit@example.com',
            'password' => 'incorrect-password',
        ])->assertUnauthorized();
    }

    $response = $this->postJson('/api/auth/system/login', [
        'email' => 'shared-limit@example.com',
        'password' => 'incorrect-password',
    ]);

    $response
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'rate_limit_exceeded')
        ->assertJsonStructure(['code', 'message']);

    expect($response->headers->get('Retry-After'))->not->toBeNull()
        ->and($response->headers->get(AssignRequestId::HEADER))->toBeString();
    Sleep::assertSleptTimes(5);
});

it('validates system login input before attempting authentication', function (): void {
    $this->postJson('/api/auth/system/login', [
        'email' => 'not-an-email',
        'password' => '',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
    Sleep::assertNeverSlept();
});

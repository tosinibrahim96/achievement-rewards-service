<?php

declare(strict_types=1);

use App\Enums\AccountType;
use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

it('registers a customer and returns a least-privilege bearer token', function (): void {
    $response = $this->postJson('/api/auth/register', [
        'name' => '  Ibrahim Abdul  ',
        'email' => '  IBRAHIM@EXAMPLE.COM  ',
        'password' => 'secure-password',
        'password_confirmation' => 'secure-password',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('user.name', 'Ibrahim Abdul')
        ->assertJsonPath('user.email', 'ibrahim@example.com')
        ->assertJsonPath('user.account_type', AccountType::Customer->value)
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('abilities', TokenAbility::customerValues())
        ->assertJsonMissingPath('data')
        ->assertJsonMissingPath('user.password');

    expect($response->json('token'))->toBeString();

    $user = User::query()->where('email', 'ibrahim@example.com')->firstOrFail();
    $token = $user->tokens()->sole();

    expect($user->account_type)->toBe(AccountType::Customer)
        ->and(Hash::check('secure-password', $user->password))->toBeTrue()
        ->and($user->password)->not->toBe('secure-password')
        ->and($token)->toBeInstanceOf(PersonalAccessToken::class)
        ->and($token->name)->toBe('api')
        ->and($token->abilities)->toBe(TokenAbility::customerValues())
        ->and($token->abilities)->not->toContain(TokenAbility::PurchasesWrite->value);
});

it('does not allow public registration to choose a privileged account type', function (): void {
    $this->postJson('/api/auth/register', [
        'name' => 'Checkout System',
        'email' => 'checkout@example.com',
        'password' => 'secure-password',
        'password_confirmation' => 'secure-password',
        'account_type' => AccountType::System->value,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account_type');

    expect(User::query()->where('email', 'checkout@example.com')->exists())->toBeFalse();
});

it('treats email addresses as case-insensitive for uniqueness', function (): void {
    User::factory()->create(['email' => 'customer@example.com']);

    $this->postJson('/api/auth/register', [
        'name' => 'Another Customer',
        'email' => 'CUSTOMER@EXAMPLE.COM',
        'password' => 'secure-password',
        'password_confirmation' => 'secure-password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('validates registration input', function (array $overrides, string $invalidField): void {
    $payload = array_replace([
        'name' => 'Ibrahim Abdul',
        'email' => 'ibrahim@example.com',
        'password' => 'secure-password',
        'password_confirmation' => 'secure-password',
    ], $overrides);

    $this->postJson('/api/auth/register', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($invalidField);
})->with([
    'name is required' => [['name' => null], 'name'],
    'email must be valid' => [['email' => 'not-an-email'], 'email'],
    'password must be at least eight characters' => [
        ['password' => 'short', 'password_confirmation' => 'short'],
        'password',
    ],
    'password must be confirmed' => [['password_confirmation' => 'different-password'], 'password'],
    'account type must be absent even when null' => [['account_type' => null], 'account_type'],
]);

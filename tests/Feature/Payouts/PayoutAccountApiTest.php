<?php

declare(strict_types=1);

use App\Actions\Payouts\RegisterPayoutAccount;
use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PaymentProviderFailure;
use App\Enums\TokenAbility;
use App\Events\PayoutAccountVerified;
use App\Http\Middleware\AssignRequestId;
use App\Infrastructure\Payments\PaymentProviderRegistry;
use App\Models\PayoutAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Monolog\Handler\TestHandler;
use Tests\Support\FailingRecipientGateway;

uses(DatabaseMigrations::class);

/** @return array<string, string> */
function payoutAccountHeaders(User $user, array $abilities): array
{
    return [
        'Authorization' => 'Bearer '.$user->createToken('payout-account-test', $abilities)->plainTextToken,
        'Accept' => 'application/json',
    ];
}

/** @return array{account_number: string, bank_code: string} */
function validPayoutAccountPayload(): array
{
    return [
        'account_number' => '0000001234',
        'bank_code' => '057',
    ];
}

beforeEach(function (): void {
    config()->set('app.key', 'base64:payout-account-api-test-key');
    config()->set('payments.default', PaymentProvider::Fake->value);
    config()->set('payments.fake.payout_account_scenario', 'success');
});

it('requires authentication the payout ability and a customer identity', function (): void {
    $customer = User::factory()->create();

    $this->putJson('/api/me/payout-account', validPayoutAccountPayload())
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');

    $this->putJson(
        '/api/me/payout-account',
        validPayoutAccountPayload(),
        payoutAccountHeaders($customer, []),
    )
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');

    $system = User::factory()->system()->create();

    $this->putJson(
        '/api/me/payout-account',
        validPayoutAccountPayload(),
        payoutAccountHeaders($system, [TokenAbility::PayoutAccountsWrite->value]),
    )
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');

    expect(PayoutAccount::query()->count())->toBe(0);
});

it('returns masked payout account details and logs changes with request ids', function (): void {
    Event::fake([PayoutAccountVerified::class]);
    $customer = User::factory()->create();
    $headers = payoutAccountHeaders($customer, [TokenAbility::PayoutAccountsWrite->value]);
    $accountNumber = '0000001234';
    $logHandler = new TestHandler;
    logger()->getLogger()->pushHandler($logHandler);

    $created = $this->putJson('/api/me/payout-account', [
        'account_number' => "  {$accountNumber}  ",
        'bank_code' => ' 057 ',
    ], $headers)->assertCreated();

    expect(array_keys($created->json()))->toBe([
        'id',
        'provider',
        'account_name',
        'bank_name',
        'bank_code',
        'masked_account_number',
        'currency',
        'verified_at',
    ]);

    $created
        ->assertJsonPath('provider', PaymentProvider::Fake->value)
        ->assertJsonPath('account_name', 'Demo Customer')
        ->assertJsonPath('bank_name', 'Demo Bank')
        ->assertJsonPath('bank_code', '057')
        ->assertJsonPath('masked_account_number', '******1234')
        ->assertJsonPath('currency', Currency::Ngn->value)
        ->assertJsonMissingPath('data')
        ->assertJsonMissingPath('user_id')
        ->assertJsonMissingPath('account_last_four')
        ->assertJsonMissingPath('provider_recipient_code');

    expect($created->getContent())->not->toContain($accountNumber)
        ->and($created->json('verified_at'))->toBeString()->toEndWith('Z')
        ->and(json_encode($logHandler->getRecords(), JSON_THROW_ON_ERROR))->not->toContain($accountNumber);

    $replaced = $this->putJson('/api/me/payout-account', [
        'account_number' => '0000009876',
        'bank_code' => '058',
    ], $headers)->assertOk();

    expect($replaced->json('id'))->toBe($created->json('id'))
        ->and($replaced->json('masked_account_number'))->toBe('******9876')
        ->and(PayoutAccount::query()->whereBelongsTo($customer)->count())->toBe(1);

    $payoutAccountLogs = collect($logHandler->getRecords())
        ->filter(static fn ($record): bool => $record->message === 'payout_account.saved')
        ->values();
    $creationLog = $payoutAccountLogs->get(0);
    $replacementLog = $payoutAccountLogs->get(1);
    $createdRequestId = $created->headers->get(AssignRequestId::HEADER);
    $replacedRequestId = $replaced->headers->get(AssignRequestId::HEADER);

    expect($payoutAccountLogs)->toHaveCount(2)
        ->and($creationLog->context)->toBe([
            'user_id' => $customer->id,
            'payout_account_id' => $created->json('id'),
            'provider' => PaymentProvider::Fake->value,
            'result' => 'created',
        ])->and($replacementLog->context)->toBe([
            'user_id' => $customer->id,
            'payout_account_id' => $created->json('id'),
            'provider' => PaymentProvider::Fake->value,
            'result' => 'replaced',
        ])->and($createdRequestId)->toBeString()
        ->and($replacedRequestId)->toBeString()
        ->and($replacedRequestId)->not->toBe($createdRequestId)
        ->and($creationLog->extra[AssignRequestId::ATTRIBUTE] ?? null)->toBe($createdRequestId)
        ->and($replacementLog->extra[AssignRequestId::ATTRIBUTE] ?? null)->toBe($replacedRequestId)
        ->and($creationLog->extra)->not->toHaveKey('correlation_id')
        ->and($replacementLog->extra)->not->toHaveKey('correlation_id')
        ->and(json_encode($payoutAccountLogs->all(), JSON_THROW_ON_ERROR))->not->toContain($accountNumber)
        ->and(json_encode($payoutAccountLogs->all(), JSON_THROW_ON_ERROR))->not->toContain('0000009876');
});

it('validates string bank details and rejects every unexpected customer field', function (array $payload, string $field): void {
    $customer = User::factory()->create();

    $this->putJson(
        '/api/me/payout-account',
        $payload,
        payoutAccountHeaders($customer, [TokenAbility::PayoutAccountsWrite->value]),
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'numeric account number' => [['account_number' => 1234, 'bank_code' => '057'], 'account_number'],
    'short account number' => [['account_number' => '00001234', 'bank_code' => '057'], 'account_number'],
    'non-digit account number' => [['account_number' => '00000012x4', 'bank_code' => '057'], 'account_number'],
    'numeric bank code' => [['account_number' => '0000001234', 'bank_code' => 57], 'bank_code'],
    'invalid bank code' => [['account_number' => '0000001234', 'bank_code' => '57'], 'bank_code'],
    'customer supplied name' => [[...validPayoutAccountPayload(), 'account_name' => 'Attacker'], 'account_name'],
    'cross-customer target' => [[...validPayoutAccountPayload(), 'user_id' => 999], 'user_id'],
    'provider override' => [[...validPayoutAccountPayload(), 'provider' => 'paystack'], 'provider'],
]);

it('maps sanitized provider failures through the central API boundary', function (
    PaymentProviderFailure $failure,
    int $status,
    string $code,
): void {
    $customer = User::factory()->create();
    $accountNumber = '0000001234';
    app()->instance(
        PaymentProviderRegistry::class,
        new PaymentProviderRegistry(
            recipientGateways: [new FailingRecipientGateway($failure)],
            transferGateways: [],
            defaultProvider: PaymentProvider::Fake->value,
        ),
    );

    $response = $this->putJson(
        '/api/me/payout-account',
        ['account_number' => $accountNumber, 'bank_code' => '057'],
        payoutAccountHeaders($customer, [TokenAbility::PayoutAccountsWrite->value]),
    );

    $response
        ->assertStatus($status)
        ->assertJsonPath('code', $code)
        ->assertJsonMissingPath('errors');

    expect($response->getContent())->not->toContain($accountNumber)
        ->and(PayoutAccount::query()->count())->toBe(0);
})->with([
    'rejected' => [PaymentProviderFailure::RecipientRejected, 422, 'payout_account_rejected'],
    'unavailable' => [PaymentProviderFailure::Unavailable, 503, 'payment_provider_unavailable'],
    'malformed response' => [PaymentProviderFailure::MalformedResponse, 502, 'payment_provider_invalid_response'],
    'timeout' => [PaymentProviderFailure::Timeout, 504, 'payment_provider_timeout'],
]);

it('maps a recipient identity conflict without replacing the previous destination or leaking input', function (): void {
    $owner = User::factory()->create();
    $customer = User::factory()->create();
    $targetAccountNumber = '0000001234';
    $original = app(RegisterPayoutAccount::class)->handle(
        $customer,
        new RegisterPayoutAccountInput('0000009876', '058'),
    )->payoutAccount;
    app(RegisterPayoutAccount::class)->handle(
        $owner,
        new RegisterPayoutAccountInput($targetAccountNumber, '057'),
    );
    Event::fake([PayoutAccountVerified::class]);

    $response = $this->putJson(
        '/api/me/payout-account',
        ['account_number' => $targetAccountNumber, 'bank_code' => '057'],
        payoutAccountHeaders($customer, [TokenAbility::PayoutAccountsWrite->value]),
    );

    $response
        ->assertConflict()
        ->assertExactJson([
            'code' => 'payout_account_conflict',
            'message' => 'The payout account conflicts with an existing destination.',
        ]);

    $preserved = PayoutAccount::query()->findOrFail($original->id);

    expect($response->getContent())->not->toContain($targetAccountNumber)
        ->and($preserved->account_last_four)->toBe('9876')
        ->and(PayoutAccount::query()->whereBelongsTo($customer)->count())->toBe(1)
        ->and(PayoutAccount::query()->whereBelongsTo($owner)->count())->toBe(1);
    Event::assertNotDispatched(PayoutAccountVerified::class);
});

it('rate limits provider-backed updates per customer without sharing the limit', function (): void {
    $customer = User::factory()->create();
    $otherCustomer = User::factory()->create();
    $headers = payoutAccountHeaders($customer, [TokenAbility::PayoutAccountsWrite->value]);

    foreach (range(1, 5) as $attempt) {
        $response = $this->putJson('/api/me/payout-account', validPayoutAccountPayload(), $headers);

        $response->assertStatus($attempt === 1 ? 201 : 200);
    }

    $limited = $this->putJson('/api/me/payout-account', validPayoutAccountPayload(), $headers);

    $limited
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'rate_limit_exceeded')
        ->assertHeader('Retry-After');

    Auth::forgetGuards();

    $this->putJson('/api/me/payout-account', [
        'account_number' => '0000005678',
        'bank_code' => '058',
    ], payoutAccountHeaders($otherCustomer, [TokenAbility::PayoutAccountsWrite->value]))
        ->assertCreated();
});

it('denies another customer through the payout account policy', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $payoutAccount = PayoutAccount::factory()->for($owner)->create();

    expect(Gate::forUser($owner)->allows('update', $payoutAccount))->toBeTrue()
        ->and(Gate::forUser($other)->denies('update', $payoutAccount))->toBeTrue();
});

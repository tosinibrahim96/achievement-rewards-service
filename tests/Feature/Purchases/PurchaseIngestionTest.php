<?php

declare(strict_types=1);

use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\TokenAbility;
use App\Events\PurchaseCompleted;
use App\Http\Middleware\AssignRequestId;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

uses(DatabaseMigrations::class);

/** @return array<string, string> */
function purchaseIngestionHeaders(User $user, array $abilities): array
{
    return [
        'Authorization' => 'Bearer '.$user->createToken('purchase-ingestion-test', $abilities)->plainTextToken,
        'Accept' => 'application/json',
    ];
}

/** @return array<string, int|string> */
function validPurchasePayload(User $customer): array
{
    return [
        'user_id' => $customer->id,
        'external_reference' => '  ORDER-10042  ',
        'amount_minor' => 2_500_000,
        'currency' => 'ngn',
        'completed_at' => '2026-08-21T14:30:00+00:00',
    ];
}

it('requires a valid bearer token', function (): void {
    $customer = User::factory()->create();

    $this->postJson('/api/internal/purchases', validPurchasePayload($customer))
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

it('requires the purchases write ability', function (): void {
    $customer = User::factory()->create();
    $system = User::factory()->system()->create();

    $this->postJson(
        '/api/internal/purchases',
        validPurchasePayload($customer),
        purchaseIngestionHeaders($system, []),
    )
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
});

it('requires a system identity even when a customer token has the ability', function (): void {
    $customer = User::factory()->create();

    $this->postJson(
        '/api/internal/purchases',
        validPurchasePayload($customer),
        purchaseIngestionHeaders($customer, [TokenAbility::PurchasesWrite->value]),
    )
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
});

it('records a completed purchase and normalizes its transport input', function (): void {
    Event::fake([PurchaseCompleted::class]);

    $customer = User::factory()->create();
    $system = User::factory()->system()->create();

    $response = $this->postJson(
        '/api/internal/purchases',
        validPurchasePayload($customer),
        purchaseIngestionHeaders($system, [TokenAbility::PurchasesWrite->value]),
    );

    $response
        ->assertCreated()
        ->assertJsonPath('purchase.user_id', $customer->id)
        ->assertJsonPath('purchase.external_reference', 'ORDER-10042')
        ->assertJsonPath('purchase.amount_minor', 2_500_000)
        ->assertJsonPath('purchase.currency', Currency::Ngn->value)
        ->assertJsonPath('purchase.completed_at', '2026-08-21T14:30:00.000000Z')
        ->assertJsonPath('was_duplicate', false)
        ->assertJsonMissingPath('data')
        ->assertJsonMissingPath('purchase.correlation_id');

    expect(Purchase::query()->count())->toBe(1)
        ->and(Purchase::query()->sole()->correlation_id)->toHaveLength(26);

    Event::assertDispatchedTimes(PurchaseCompleted::class, 1);
});

it('returns the existing purchase for an identical replay without dispatching twice', function (): void {
    Event::fake([PurchaseCompleted::class]);

    $customer = User::factory()->create();
    $system = User::factory()->system()->create();
    $headers = purchaseIngestionHeaders($system, [TokenAbility::PurchasesWrite->value]);
    $payload = validPurchasePayload($customer);

    $created = $this->postJson('/api/internal/purchases', $payload, $headers)->assertCreated();
    $duplicate = $this->postJson('/api/internal/purchases', $payload, $headers)
        ->assertOk()
        ->assertJsonPath('was_duplicate', true);

    expect($duplicate->json('purchase.id'))->toBe($created->json('purchase.id'))
        ->and(Purchase::query()->count())->toBe(1);

    Event::assertDispatchedTimes(PurchaseCompleted::class, 1);
});

it('logs only allowed fields for new and repeated purchases', function (): void {
    Event::fake([PurchaseCompleted::class]);
    $customer = User::factory()->create();
    $system = User::factory()->system()->create();
    $headers = purchaseIngestionHeaders($system, [TokenAbility::PurchasesWrite->value]);
    $payload = validPurchasePayload($customer);
    $createdContext = [];
    $duplicateContext = [];
    $createdRequestId = null;
    $duplicateRequestId = null;
    $previousCorrelationId = 'previous-workflow';
    Context::add('correlation_id', $previousCorrelationId);

    Log::shouldReceive('info')->once()->with(
        'purchase.processed',
        Mockery::on(function (array $context) use (&$createdContext, &$createdRequestId): bool {
            $createdContext = $context;
            $createdRequestId = Context::get(AssignRequestId::ATTRIBUTE);

            return Context::get('correlation_id') === ($context['correlation_id'] ?? null);
        }),
    );
    Log::shouldReceive('debug')->once()->with(
        'purchase.processed',
        Mockery::on(function (array $context) use (&$duplicateContext, &$duplicateRequestId): bool {
            $duplicateContext = $context;
            $duplicateRequestId = Context::get(AssignRequestId::ATTRIBUTE);

            return Context::get('correlation_id') === ($context['correlation_id'] ?? null);
        }),
    );

    $created = $this->postJson('/api/internal/purchases', $payload, $headers)->assertCreated();
    expect(Context::get('correlation_id'))->toBe($previousCorrelationId);
    $duplicate = $this->postJson('/api/internal/purchases', $payload, $headers)->assertOk();
    expect(Context::get('correlation_id'))->toBe($previousCorrelationId);
    $purchase = Purchase::query()->sole();

    expect(array_keys($createdContext))->toBe([
        'purchase_id',
        'user_id',
        'correlation_id',
        'result',
    ])->and($createdContext)->toBe([
        'purchase_id' => $purchase->id,
        'user_id' => $customer->id,
        'correlation_id' => $purchase->correlation_id,
        'result' => 'created',
    ])->and($duplicateContext)->toBe([
        'purchase_id' => $purchase->id,
        'user_id' => $customer->id,
        'correlation_id' => $purchase->correlation_id,
        'result' => 'duplicate',
    ])->and($createdRequestId)->toBeString()
        ->and($duplicateRequestId)->toBeString()
        ->and($createdRequestId)->toBe($created->headers->get(AssignRequestId::HEADER))
        ->and($duplicateRequestId)->toBe($duplicate->headers->get(AssignRequestId::HEADER))
        ->and($duplicateRequestId)->not->toBe($createdRequestId)
        ->and(json_encode([$createdContext, $duplicateContext], JSON_THROW_ON_ERROR))
        ->not->toContain('ORDER-10042');
});

it('rejects a conflicting reuse of an external reference', function (): void {
    Event::fake([PurchaseCompleted::class]);

    $customer = User::factory()->create();
    $system = User::factory()->system()->create();
    $headers = purchaseIngestionHeaders($system, [TokenAbility::PurchasesWrite->value]);
    $payload = validPurchasePayload($customer);

    $this->postJson('/api/internal/purchases', $payload, $headers)->assertCreated();
    Log::spy();

    $this->postJson('/api/internal/purchases', [...$payload, 'amount_minor' => 2_500_001], $headers)
        ->assertConflict()
        ->assertJson([
            'code' => 'purchase_reference_conflict',
            'message' => 'The external reference is already associated with a different purchase.',
        ]);

    expect(Purchase::query()->count())->toBe(1);
    Event::assertDispatchedTimes(PurchaseCompleted::class, 1);
    Log::shouldNotHaveReceived('info', ['purchase.processed', Mockery::type('array')]);
    Log::shouldNotHaveReceived('debug', ['purchase.processed', Mockery::type('array')]);
});

it('validates completed purchase input', function (array $override, string $field): void {
    $customer = User::factory()->create();
    $system = User::factory()->system()->create();

    $this->postJson(
        '/api/internal/purchases',
        [...validPurchasePayload($customer), ...$override],
        purchaseIngestionHeaders($system, [TokenAbility::PurchasesWrite->value]),
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unknown customer' => [['user_id' => 999_999], 'user_id'],
    'non-positive amount' => [['amount_minor' => 0], 'amount_minor'],
    'unsupported currency' => [['currency' => 'USD'], 'currency'],
    'missing completion time' => [['completed_at' => null], 'completed_at'],
    'invalid completion time' => [['completed_at' => 'not-a-date'], 'completed_at'],
    'missing external reference' => [['external_reference' => '  '], 'external_reference'],
]);

it('does not accept a system identity as the purchase customer', function (): void {
    $targetSystem = User::factory()->system()->create();
    $caller = User::factory()->system()->create();

    $this->postJson(
        '/api/internal/purchases',
        validPurchasePayload($targetSystem),
        purchaseIngestionHeaders($caller, [TokenAbility::PurchasesWrite->value]),
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_id');
});

it('rate limits purchase ingestion per system identity', function (): void {
    $system = User::factory()->system()->create();
    $headers = purchaseIngestionHeaders($system, [TokenAbility::PurchasesWrite->value]);

    for ($attempt = 1; $attempt <= 120; $attempt++) {
        $this->postJson('/api/internal/purchases', [], $headers)->assertUnprocessable();
    }

    $this->postJson('/api/internal/purchases', [], $headers)
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'rate_limit_exceeded');
});

it('keeps the public customer token policy unchanged', function (): void {
    expect(AccountType::Customer)->not->toBe(AccountType::System)
        ->and(TokenAbility::customerValues())->not->toContain(TokenAbility::PurchasesWrite->value);
});

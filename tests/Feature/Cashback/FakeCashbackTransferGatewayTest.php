<?php

declare(strict_types=1);

use App\Data\Payments\CashbackTransferRequest;
use App\Enums\CashbackTransferErrorCode;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PaymentProviderFailure;
use App\Enums\PayoutStatus;
use App\Exceptions\Payments\PaymentProviderException;
use App\Infrastructure\Payments\FakeCashbackTransferGateway;
use App\Infrastructure\Payments\FakeTransferEffectRegistry;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

/** @return array{FakeTransferEffectRegistry, CashbackTransferRequest} */
function fakeTransferFixture(): array
{
    $registry = new FakeTransferEffectRegistry(
        'testing',
        'pest-'.Str::lower((string) Str::ulid()),
    );
    $request = new CashbackTransferRequest(
        providerReference: 'cashback-'.Str::lower((string) Str::ulid()),
        recipientCode: 'RCP_FAKE_TEST',
        amountMinor: 30_000,
        currency: Currency::Ngn,
    );

    return [$registry, $request];
}

it('atomically creates one non-expiring fake success effect with a deterministic transfer code', function (): void {
    [$effects, $request] = fakeTransferFixture();
    $gateway = new FakeCashbackTransferGateway($effects, 'success');

    try {
        $first = $gateway->initiateTransfer($request);
        $second = $gateway->initiateTransfer($request);
        $ttl = (int) Redis::connection('default')->command('ttl', [
            $effects->keyForReference($request->providerReference),
        ]);

        expect($gateway->provider())->toBe(PaymentProvider::Fake)
            ->and($first->status)->toBe(PayoutStatus::Succeeded)
            ->and($first->transferCode)->toBe('TRF_FAKE_'.hash('sha256', $request->providerReference))
            ->and($first->latencyMs)->toBe(0)
            ->and($second->transferCode)->toBe($first->transferCode)
            ->and($ttl)->toBe(-1)
            ->and($effects->findForRequest($request)?->status)
            ->toBe(PayoutStatus::Succeeded);
    } finally {
        $effects->forget($request->providerReference);
    }
});

it('accepts only safe Redis key parts for fake transfer effects', function (
    string $environment,
    string $namespace,
    bool $isValid,
): void {
    if ($isValid) {
        expect(new FakeTransferEffectRegistry($environment, $namespace))
            ->toBeInstanceOf(FakeTransferEffectRegistry::class);

        return;
    }

    expect(fn () => new FakeTransferEffectRegistry($environment, $namespace))
        ->toThrow(LogicException::class);
})->with([
    'letters numbers dot underscore and hyphen' => ['testing', 'pest_1.2-test', true],
    'empty environment' => ['', 'safe', false],
    'space' => ['test environment', 'safe', false],
    'colon would add a key segment' => ['testing', 'unsafe:segment', false],
    'slash' => ['testing', 'unsafe/segment', false],
    'line break' => ['testing', "unsafe\nsegment", false],
]);

it('rejects stored fake effects with a missing version, unknown version, invalid status, or empty code', function (
    array $storedRecord,
): void {
    [$effects, $request] = fakeTransferFixture();
    $key = $effects->keyForReference($request->providerReference);

    Redis::connection('default')->command('set', [
        $key,
        json_encode($storedRecord, JSON_THROW_ON_ERROR),
    ]);

    try {
        expect(fn () => $effects->findByReference($request->providerReference))
            ->toThrow(RuntimeException::class, 'invalid stored representation');
    } finally {
        $effects->forget($request->providerReference);
    }
})->with([
    'missing version' => [[
        'status' => 'succeeded',
        'transfer_code' => 'TRF_FAKE_STORED',
    ]],
    'unknown version' => [[
        'version' => 2,
        'status' => 'succeeded',
        'transfer_code' => 'TRF_FAKE_STORED',
    ]],
    'invalid status' => [[
        'version' => 1,
        'status' => 'failed',
        'transfer_code' => 'TRF_FAKE_STORED',
    ]],
    'empty transfer code' => [[
        'version' => 1,
        'status' => 'succeeded',
        'transfer_code' => '',
    ]],
]);

it('stores a pending transfer with its code in the fake effect registry', function (): void {
    [$effects, $request] = fakeTransferFixture();
    $gateway = new FakeCashbackTransferGateway($effects, 'pending');

    try {
        $result = $gateway->initiateTransfer($request);

        expect($result->status)->toBe(PayoutStatus::Pending)
            ->and($result->transferCode)->not->toBeNull()
            ->and($effects->findForRequest($request)?->status)
            ->toBe(PayoutStatus::Pending);
    } finally {
        $effects->forget($request->providerReference);
    }
});

it('does not save the reference when the fake provider creates no transfer', function (
    string $scenario,
    PayoutStatus $expectedStatus,
    ?int $expectedBalance,
    CashbackTransferErrorCode $expectedErrorCode,
): void {
    [$effects, $request] = fakeTransferFixture();
    $gateway = new FakeCashbackTransferGateway($effects, $scenario);

    try {
        $result = $gateway->initiateTransfer($request);

        expect($result->status)->toBe($expectedStatus)
            ->and($result->transferCode)->toBeNull()
            ->and($result->errorCode)->toBe($expectedErrorCode)
            ->and($result->observedBalanceMinor)->toBe($expectedBalance)
            ->and($effects->findForRequest($request))->toBeNull()
            ->and(Redis::connection('default')->command('exists', [
                $effects->keyForReference($request->providerReference),
            ]))->toBe(0);
    } finally {
        $effects->forget($request->providerReference);
    }
})->with([
    'insufficient funds' => [
        'insufficient_funds',
        PayoutStatus::InsufficientFunds,
        0,
        CashbackTransferErrorCode::InsufficientFunds,
    ],
    'rejected' => [
        'permanent_failure',
        PayoutStatus::Rejected,
        null,
        CashbackTransferErrorCode::PermanentFailure,
    ],
]);

it('treats an existing effect as authoritative over a later scenario change', function (): void {
    [$effects, $request] = fakeTransferFixture();

    try {
        $created = (new FakeCashbackTransferGateway($effects, 'success'))->initiateTransfer($request);
        $replayed = (new FakeCashbackTransferGateway($effects, 'permanent_failure'))->initiateTransfer($request);

        expect($replayed->status)->toBe(PayoutStatus::Succeeded)
            ->and($replayed->transferCode)->toBe($created->transferCode);
    } finally {
        $effects->forget($request->providerReference);
    }
});

it('isolates the same stable reference between explicit fake-effect namespaces', function (): void {
    [, $request] = fakeTransferFixture();
    $firstEffects = new FakeTransferEffectRegistry('testing', 'pest-first-'.Str::lower((string) Str::ulid()));
    $secondEffects = new FakeTransferEffectRegistry('testing', 'pest-second-'.Str::lower((string) Str::ulid()));

    try {
        (new FakeCashbackTransferGateway($firstEffects, 'success'))->initiateTransfer($request);
        (new FakeCashbackTransferGateway($secondEffects, 'pending'))->initiateTransfer($request);

        expect($firstEffects->keyForReference($request->providerReference))
            ->not->toBe($secondEffects->keyForReference($request->providerReference))
            ->and($firstEffects->findByReference($request->providerReference)?->status)
            ->toBe(PayoutStatus::Succeeded)
            ->and($secondEffects->findByReference($request->providerReference)?->status)
            ->toBe(PayoutStatus::Pending);
    } finally {
        $firstEffects->forget($request->providerReference);
        $secondEffects->forget($request->providerReference);
    }
});

it('rejects reuse of one fake reference for different transfer facts', function (): void {
    [$effects, $request] = fakeTransferFixture();
    $gateway = new FakeCashbackTransferGateway($effects, 'success');

    try {
        $gateway->initiateTransfer($request);

        $changedRequest = new CashbackTransferRequest(
            providerReference: $request->providerReference,
            recipientCode: 'RCP_DIFFERENT',
            amountMinor: $request->amountMinor,
            currency: $request->currency,
        );

        expect(fn () => $gateway->initiateTransfer($changedRequest))->toThrow(
            LogicException::class,
            'The fake transfer reference is already bound to different payout details.',
        );
    } finally {
        $effects->forget($request->providerReference);
    }
});

it('reports deterministic fake balances without using them as transfer reservations', function (): void {
    [$effects] = fakeTransferFixture();

    expect((new FakeCashbackTransferGateway($effects, 'success'))
        ->availableBalance(Currency::Ngn)->amountMinor)->toBe(1_000_000_000)
        ->and((new FakeCashbackTransferGateway($effects, 'insufficient_funds'))
            ->availableBalance(Currency::Ngn)->amountMinor)->toBe(0);
});

it('fails closed when the configured fake transfer scenario is unknown', function (): void {
    [$effects] = fakeTransferFixture();

    try {
        new FakeCashbackTransferGateway($effects, 'not-a-scenario');
        test()->fail('An unknown fake transfer scenario must fail during gateway construction.');
    } catch (PaymentProviderException $exception) {
        expect($exception->failure)->toBe(PaymentProviderFailure::Unavailable);
    }
});

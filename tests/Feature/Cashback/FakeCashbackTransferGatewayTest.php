<?php

declare(strict_types=1);

use App\Data\Payments\CashbackTransferRequest;
use App\Enums\CashbackTransferErrorCode;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PaymentProviderFailure;
use App\Enums\PayoutAttemptStatus;
use App\Exceptions\Payments\PaymentProviderException;
use App\Infrastructure\Payments\FakeCashbackTransferGateway;
use App\Infrastructure\Payments\FakeTransferEffectRegistry;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use LogicException;

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
            ->and($first->status)->toBe(PayoutAttemptStatus::Succeeded)
            ->and($first->transferCode)->toBe('TRF_FAKE_'.hash('sha256', $request->providerReference))
            ->and($second->transferCode)->toBe($first->transferCode)
            ->and($ttl)->toBe(-1)
            ->and($gateway->verifyTransfer($request->providerReference)->result?->status)
            ->toBe(PayoutAttemptStatus::Succeeded);
    } finally {
        $effects->forget($request->providerReference);
    }
});

it('persists a pending provider-created effect and verifies the same lifecycle', function (): void {
    [$effects, $request] = fakeTransferFixture();
    $gateway = new FakeCashbackTransferGateway($effects, 'pending');

    try {
        $result = $gateway->initiateTransfer($request);

        expect($result->status)->toBe(PayoutAttemptStatus::Pending)
            ->and($result->transferCode)->not->toBeNull()
            ->and($gateway->verifyTransfer($request->providerReference)->result?->status)
            ->toBe(PayoutAttemptStatus::Pending);
    } finally {
        $effects->forget($request->providerReference);
    }
});

it('does not consume the stable reference for pre-creation fake outcomes', function (
    string $scenario,
    PayoutAttemptStatus $expectedStatus,
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
            ->and($gateway->verifyTransfer($request->providerReference)->result)->toBeNull()
            ->and(Redis::connection('default')->command('exists', [
                $effects->keyForReference($request->providerReference),
            ]))->toBe(0);
    } finally {
        $effects->forget($request->providerReference);
    }
})->with([
    'insufficient funds' => [
        'insufficient_funds',
        PayoutAttemptStatus::InsufficientFunds,
        0,
        CashbackTransferErrorCode::InsufficientFunds,
    ],
    'permanent rejection' => [
        'permanent_failure',
        PayoutAttemptStatus::PermanentRejection,
        null,
        CashbackTransferErrorCode::PermanentFailure,
    ],
]);

it('treats an existing effect as authoritative over a later scenario change', function (): void {
    [$effects, $request] = fakeTransferFixture();

    try {
        $created = (new FakeCashbackTransferGateway($effects, 'success'))->initiateTransfer($request);
        $replayed = (new FakeCashbackTransferGateway($effects, 'permanent_failure'))->initiateTransfer($request);

        expect($replayed->status)->toBe(PayoutAttemptStatus::Succeeded)
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
            ->toBe(PayoutAttemptStatus::Succeeded)
            ->and($secondEffects->findByReference($request->providerReference)?->status)
            ->toBe(PayoutAttemptStatus::Pending);
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

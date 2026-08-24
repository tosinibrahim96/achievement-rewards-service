<?php

declare(strict_types=1);

use App\Actions\Cashback\ProcessCashbackPayment;
use App\Actions\Payouts\RegisterPayoutAccount;
use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Enums\CashbackRewardStatus;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PayoutAttemptStatus;
use App\Events\PayoutAccountVerified;
use App\Infrastructure\Payments\FakeCashbackTransferGateway;
use App\Infrastructure\Payments\FakeTransferEffectRegistry;
use App\Infrastructure\Payments\FakeTransferRecipientGateway;
use App\Models\CashbackReward;
use App\Models\PayoutAccount;
use App\Models\PayoutAttempt;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\ConcurrentRunner;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('app.key', 'base64:cashback-payment-concurrency-key');
    config()->set('cache.default', 'redis');
    config()->set('payments.default', PaymentProvider::Fake->value);
    config()->set('payments.fake.payout_account_scenario', 'success');
    config()->set('payments.fake.transfer_scenario', 'success');
    config()->set(
        'payments.fake.transfer_effect_namespace',
        'pest-concurrency-'.Str::lower((string) Str::ulid()),
    );
});

it('creates one durable claim and one process-visible fake effect when workers compete', function (): void {
    $user = User::factory()->create();
    PayoutAccount::factory()->for($user)->create();
    $userBadge = UserBadge::factory()->for($user)->create();
    $reward = CashbackReward::factory()
        ->for($user)
        ->for($userBadge, 'userBadge')
        ->readyForPayout()
        ->create();
    $effects = app(FakeTransferEffectRegistry::class);
    $effects->forget($reward->provider_reference);

    try {
        (new ConcurrentRunner)->run([
            static fn () => app(ProcessCashbackPayment::class)->handle($reward->id),
            static fn () => app(ProcessCashbackPayment::class)->handle($reward->id),
        ]);

        $reward = CashbackReward::query()->findOrFail($reward->id);
        $attempt = PayoutAttempt::query()->where('cashback_reward_id', $reward->id)->sole();
        $effectKey = $effects->keyForReference($reward->provider_reference);

        expect($reward->status)->toBe(CashbackRewardStatus::Paid)
            ->and($attempt->status)->toBe(PayoutAttemptStatus::Succeeded)
            ->and(PayoutAttempt::query()->where('cashback_reward_id', $reward->id)->count())->toBe(1)
            ->and((int) Redis::connection('default')->command('exists', [$effectKey]))->toBe(1)
            ->and((int) Redis::connection('default')->command('ttl', [$effectKey]))->toBe(-1);
    } finally {
        $effects->forget($reward->provider_reference);
    }
});

it('atomically creates one authoritative fake effect when gateways compete directly', function (): void {
    $request = new CashbackTransferRequest(
        providerReference: 'cashback-'.Str::lower((string) Str::ulid()),
        recipientCode: 'RCP_FAKE_CONCURRENT_EFFECT',
        amountMinor: 30_000,
        currency: Currency::Ngn,
    );
    $effects = app(FakeTransferEffectRegistry::class);
    $effects->forget($request->providerReference);

    try {
        (new ConcurrentRunner)->run([
            static function () use ($effects, $request): void {
                $result = (new FakeCashbackTransferGateway($effects, 'success'))
                    ->initiateTransfer($request);
                $verified = $effects->findForRequest($request);

                if ($verified?->status !== $result->status) {
                    throw new RuntimeException('The success contender did not observe the winning fake effect.');
                }
            },
            static function () use ($effects, $request): void {
                $result = (new FakeCashbackTransferGateway($effects, 'pending'))
                    ->initiateTransfer($request);
                $verified = $effects->findForRequest($request);

                if ($verified?->status !== $result->status) {
                    throw new RuntimeException('The pending contender did not observe the winning fake effect.');
                }
            },
        ]);

        $effect = $effects->findForRequest($request);
        $effectKey = $effects->keyForReference($request->providerReference);

        expect($effect?->status)->toBeIn([
            PayoutAttemptStatus::Succeeded,
            PayoutAttemptStatus::Pending,
        ])
            ->and($effect?->transferCode)
            ->toBe('TRF_FAKE_'.hash('sha256', $request->providerReference))
            ->and((int) Redis::connection('default')->command('ttl', [$effectKey]))->toBe(-1);
    } finally {
        $effects->forget($request->providerReference);
    }
});

it('snapshots either complete destination when account replacement races the first claim', function (): void {
    Event::fake([PayoutAccountVerified::class]);
    $user = User::factory()->create();
    $account = PayoutAccount::factory()->for($user)->create();
    $userBadge = UserBadge::factory()->for($user)->create();
    $reward = CashbackReward::factory()
        ->for($user)
        ->for($userBadge, 'userBadge')
        ->readyForPayout()
        ->create();
    $oldRecipientCode = $account->provider_recipient_code;
    $replacementInput = new RegisterPayoutAccountInput('0000004321', '058');
    $newRecipientCode = app(FakeTransferRecipientGateway::class)
        ->createRecipient($replacementInput)
        ->recipientCode;
    $effects = app(FakeTransferEffectRegistry::class);
    $effects->forget($reward->provider_reference);

    try {
        (new ConcurrentRunner)->run([
            static fn () => app(ProcessCashbackPayment::class)->handle($reward->id),
            static fn () => app(RegisterPayoutAccount::class)->handle(
                User::query()->findOrFail($user->id),
                $replacementInput,
            ),
        ]);

        $attempt = PayoutAttempt::query()->where('cashback_reward_id', $reward->id)->sole();
        $currentAccount = PayoutAccount::query()->where('user_id', $user->id)->sole();

        expect($attempt->provider)->toBe(PaymentProvider::Fake)
            ->and($attempt->payout_account_id)->toBe($account->id)
            ->and($attempt->provider_recipient_code)->toBeIn([
                $oldRecipientCode,
                $newRecipientCode,
            ])
            ->and($currentAccount->provider_recipient_code)->toBe($newRecipientCode)
            ->and(PayoutAttempt::query()->where('cashback_reward_id', $reward->id)->count())->toBe(1);
    } finally {
        $effects->forget($reward->provider_reference);
    }
});

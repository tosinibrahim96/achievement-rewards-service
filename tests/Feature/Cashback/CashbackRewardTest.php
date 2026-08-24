<?php

declare(strict_types=1);

use App\Actions\Badges\EvaluateBadges;
use App\Actions\Cashback\CreateCashbackReward;
use App\Actions\Payouts\RegisterPayoutAccount;
use App\Actions\Purchases\RecordPurchase;
use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Data\Purchases\RecordPurchaseInput;
use App\Domain\Money\Money;
use App\Enums\CashbackRewardStatus;
use App\Enums\Currency;
use App\Events\BadgeUnlocked;
use App\Models\CashbackReward;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Models\UserBadge;
use Carbon\CarbonImmutable;
use Database\Seeders\AchievementCatalogueSeeder;
use Database\Seeders\BadgeCatalogueSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use Tests\Support\BadgeTestData;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->seed([AchievementCatalogueSeeder::class, BadgeCatalogueSeeder::class]);
    config()->set('payments.fake.payout_account_scenario', 'success');
});

it('creates coherent cashback reward factory records', function (): void {
    $reward = CashbackReward::factory()->create();

    expect($reward->user_id)->toBe($reward->userBadge->user_id)
        ->and($reward->correlation_id)->toBe($reward->userBadge->correlation_id);
});

it('creates one snapshotted reward for every newly unlocked badge', function (): void {
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 8);

    app(EvaluateBadges::class)->handle($user);

    $rewards = CashbackReward::query()->whereBelongsTo($user)->orderBy('id')->get();

    expect(UserBadge::query()->whereBelongsTo($user)->count())->toBe(3)
        ->and($rewards)->toHaveCount(3)
        ->and($rewards->pluck('amount_minor')->all())->toBe([30_000, 30_000, 30_000])
        ->and($rewards->map(fn (CashbackReward $reward): string => $reward->currency->value)->all())
        ->toBe(['NGN', 'NGN', 'NGN'])
        ->and($rewards->map(fn (CashbackReward $reward): string => $reward->status->value)->all())
        ->toBe([
            CashbackRewardStatus::AwaitingPayoutAccount->value,
            CashbackRewardStatus::AwaitingPayoutAccount->value,
            CashbackRewardStatus::AwaitingPayoutAccount->value,
        ])
        ->and($rewards->every(
            fn (CashbackReward $reward): bool => $reward->userBadge->correlation_id === $reward->correlation_id
                && $reward->userBadge->user_id === $reward->user_id,
        ))->toBeTrue()
        ->and($rewards->pluck('provider_reference')->unique())->toHaveCount(3)
        ->and($rewards->every(
            fn (CashbackReward $reward): bool => strlen($reward->provider_reference) === 35
                && preg_match('/\Acashback-[0-9a-hjkmnp-tv-z]{26}\z/', $reward->provider_reference) === 1
                && preg_match('/\A[a-z0-9_-]{16,50}\z/', $reward->provider_reference) === 1,
        ))->toBeTrue();
});

it('creates every account-first badge reward ready before its listener runs', function (): void {
    Event::fake([BadgeUnlocked::class]);
    $user = User::factory()->create();
    PayoutAccount::factory()->for($user)->create();
    BadgeTestData::giveAchievements($user, 8);

    app(EvaluateBadges::class)->handle($user);

    $rewards = CashbackReward::query()->whereBelongsTo($user)->orderBy('id')->get();

    expect($rewards)->toHaveCount(3)
        ->and($rewards->every(
            static fn (CashbackReward $reward): bool => $reward->status === CashbackRewardStatus::ReadyForPayout,
        ))->toBeTrue();
    Event::assertDispatchedTimes(BadgeUnlocked::class, 3);
});

it('changes every badge-first reward to ready inside account registration', function (): void {
    Event::fake();
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 8);
    app(EvaluateBadges::class)->handle($user);

    expect(CashbackReward::query()->whereBelongsTo($user)->pluck('status')->every(
        static fn (CashbackRewardStatus $status): bool => $status === CashbackRewardStatus::AwaitingPayoutAccount,
    ))->toBeTrue();

    app(RegisterPayoutAccount::class)->handle(
        $user,
        new RegisterPayoutAccountInput('0000001234', '057'),
    );

    expect(CashbackReward::query()->whereBelongsTo($user)->pluck('status')->every(
        static fn (CashbackRewardStatus $status): bool => $status === CashbackRewardStatus::ReadyForPayout,
    ))->toBeTrue();
});

it('rejects direct reward creation outside a caller-owned transaction', function (): void {
    $userBadge = UserBadge::factory()->create();

    expect(fn () => app(CreateCashbackReward::class)->handle($userBadge))
        ->toThrow(
            LogicException::class,
            'Cashback reward creation must run inside a database transaction.',
        )
        ->and(CashbackReward::query()->where('user_badge_id', $userBadge->id)->exists())
        ->toBeFalse();
});

it('returns a replayed reward without resetting its lifecycle', function (
    CashbackRewardStatus $status,
): void {
    $userBadge = UserBadge::factory()->create();
    $reward = CashbackReward::factory()
        ->for($userBadge->user, 'user')
        ->for($userBadge, 'userBadge')
        ->create(['status' => $status]);
    $replayed = DB::transaction(
        fn (): CashbackReward => app(CreateCashbackReward::class)->handle($userBadge),
    );

    expect($replayed->is($reward))->toBeTrue()
        ->and($replayed->status)->toBe($status)
        ->and(CashbackReward::query()->where('user_badge_id', $userBadge->id)->count())->toBe(1);
})->with([
    CashbackRewardStatus::Processing,
    CashbackRewardStatus::Paid,
]);

it('preserves existing reward snapshots when configuration changes', function (): void {
    $firstUser = User::factory()->create();
    BadgeTestData::giveAchievements($firstUser, 1);
    app(EvaluateBadges::class)->handle($firstUser);

    config()->set('rewards.badge_cashback_amount_minor', 40_000);

    $secondUser = User::factory()->create();
    BadgeTestData::giveAchievements($secondUser, 1);
    app(EvaluateBadges::class)->handle($secondUser);

    expect(CashbackReward::query()->whereBelongsTo($firstUser)->value('amount_minor'))->toBe(30_000)
        ->and(CashbackReward::query()->whereBelongsTo($secondUser)->value('amount_minor'))->toBe(40_000);
});

it('keeps the reward and its provider reference stable on evaluation replay', function (): void {
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 1);
    $evaluateBadges = app(EvaluateBadges::class);

    $evaluateBadges->handle($user);
    $initial = CashbackReward::query()->whereBelongsTo($user)->firstOrFail();
    $evaluateBadges->handle($user);
    $replayed = CashbackReward::query()->whereBelongsTo($user)->firstOrFail();

    expect($replayed->is($initial))->toBeTrue()
        ->and($replayed->provider_reference)->toBe($initial->provider_reference)
        ->and(CashbackReward::query()->whereBelongsTo($user)->count())->toBe(1);
});

it('runs the complete purchase to reward entitlement flow', function (): void {
    $user = User::factory()->create();

    app(RecordPurchase::class)->handle(new RecordPurchaseInput(
        userId: $user->id,
        externalReference: 'ORDER-PURCHASE-TO-REWARD',
        amount: new Money(2_500_000, Currency::Ngn),
        completedAt: CarbonImmutable::parse('2026-08-21T14:30:00Z'),
    ));

    expect($user->userAchievements()->count())->toBe(4)
        ->and(BadgeTestData::codesFor($user))->toBe(['beginner', 'intermediate'])
        ->and($user->cashbackRewards()->count())->toBe(2);
});

it('enforces one reward for each awarded badge at the database boundary', function (): void {
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 1);
    app(EvaluateBadges::class)->handle($user);
    $reward = CashbackReward::query()->whereBelongsTo($user)->firstOrFail();

    expect(fn () => DB::table('cashback_rewards')->insert([
        'user_id' => $user->id,
        'user_badge_id' => $reward->user_badge_id,
        'amount_minor' => 30_000,
        'currency' => Currency::Ngn->value,
        'provider_reference' => 'cashback-duplicate-award',
        'status' => CashbackRewardStatus::AwaitingPayoutAccount->value,
        'correlation_id' => $reward->correlation_id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rolls back a badge when its reward configuration is invalid', function (): void {
    Event::fake([BadgeUnlocked::class]);
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 1);
    config()->set('rewards.badge_cashback_amount_minor', 0);

    expect(fn () => app(EvaluateBadges::class)->handle($user))->toThrow(LogicException::class)
        ->and(UserBadge::query()->whereBelongsTo($user)->count())->toBe(0)
        ->and(CashbackReward::query()->whereBelongsTo($user)->count())->toBe(0);
    Event::assertNotDispatched(BadgeUnlocked::class);
});

it('enforces cashback amount currency and status invariants in postgres', function (array $invalid): void {
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 1);
    app(EvaluateBadges::class)->handle($user);
    $reward = CashbackReward::query()->whereBelongsTo($user)->firstOrFail();

    expect(fn () => DB::table('cashback_rewards')->where('id', $reward->id)->update($invalid))
        ->toThrow(QueryException::class);
})->with([
    'non-positive amount' => [['amount_minor' => 0]],
    'unsupported currency' => [['currency' => 'USD']],
    'unknown state' => [['status' => 'lost']],
]);

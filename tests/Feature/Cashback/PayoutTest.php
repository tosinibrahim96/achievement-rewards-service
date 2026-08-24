<?php

declare(strict_types=1);

use App\Enums\CashbackRewardStatus;
use App\Enums\PaymentProvider;
use App\Enums\PayoutStatus;
use App\Models\CashbackReward;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Models\UserBadge;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseMigrations::class);

it('adds nullable provider and balance observations without rewriting reward defaults', function (): void {
    $reward = CashbackReward::factory()->create();

    expect(Schema::getColumnListing('cashback_rewards'))->toContain(
        'provider',
        'last_observed_balance_minor',
        'balance_observed_at',
    )
        ->and($reward->provider)->toBeNull()
        ->and($reward->last_observed_balance_minor)->toBeNull()
        ->and($reward->balance_observed_at)->toBeNull();

    $observedAt = CarbonImmutable::parse('2026-08-23T01:30:00Z');
    $reward->update([
        'provider' => PaymentProvider::Fake,
        'last_observed_balance_minor' => 0,
        'balance_observed_at' => $observedAt,
    ]);
    $reward->refresh();

    expect($reward->provider)->toBe(PaymentProvider::Fake)
        ->and($reward->last_observed_balance_minor)->toBe(0)
        ->and($reward->balance_observed_at?->equalTo($observedAt))->toBeTrue();
});

it('creates a coherent durable payout with typed relationships and casts', function (): void {
    $payout = Payout::factory()->create();
    $reward = $payout->cashbackReward;
    $account = $payout->payoutAccount;

    expect($reward->status)->toBe(CashbackRewardStatus::Processing)
        ->and($reward->provider)->toBe(PaymentProvider::Fake)
        ->and($payout->provider)->toBe($account->provider)
        ->and($payout->provider_reference)->toBe($reward->provider_reference)
        ->and($payout->provider_recipient_code)->toBe($account->provider_recipient_code)
        ->and($payout->amount_minor)->toBe($reward->amount_minor)
        ->and($payout->currency)->toBe($reward->currency)
        ->and($payout->status)->toBe(PayoutStatus::Started)
        ->and($payout->started_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($payout->completed_at)->toBeNull()
        ->and($payout->succeeded_at)->toBeNull()
        ->and($payout->reversed_at)->toBeNull()
        ->and($payout->support_alert_requested_at)->toBeNull()
        ->and($reward->payout()->firstOrFail()->is($payout))->toBeTrue()
        ->and($reward->payout?->is($payout))->toBeTrue()
        ->and($account->payouts()->firstOrFail()->is($payout))->toBeTrue()
        ->and($account->payouts->first()?->is($payout))->toBeTrue();
});

it('allows a reward to have no payout before processing starts', function (): void {
    $reward = CashbackReward::factory()->create();

    expect($reward->payout)->toBeNull()
        ->and($reward->payout()->exists())->toBeFalse();
});

it('allows one payout account to own payouts for different rewards', function (): void {
    $user = User::factory()->create();
    $account = PayoutAccount::factory()->for($user)->create();
    $firstReward = CashbackReward::factory()
        ->for($user)
        ->for(UserBadge::factory()->for($user), 'userBadge')
        ->create();
    $secondReward = CashbackReward::factory()
        ->for($user)
        ->for(UserBadge::factory()->for($user), 'userBadge')
        ->create();
    $firstPayout = Payout::factory()->create([
        'cashback_reward_id' => $firstReward->id,
        'payout_account_id' => $account->id,
    ]);
    $secondPayout = Payout::factory()->create([
        'cashback_reward_id' => $secondReward->id,
        'payout_account_id' => $account->id,
    ]);

    expect($account->payouts()->orderBy('id')->pluck('id')->all())->toBe([
        $firstPayout->id,
        $secondPayout->id,
    ]);
});

it('casts the support-request intent timestamp without claiming delivery', function (): void {
    $payout = Payout::factory()->create([
        'support_alert_requested_at' => '2026-08-23T18:30:00+00:00',
    ]);

    expect($payout->support_alert_requested_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($payout->support_alert_requested_at?->toIso8601String())
        ->toBe('2026-08-23T18:30:00+00:00');
});

it('enforces one payout per reward', function (): void {
    $payout = Payout::factory()->create();

    expect(fn () => Payout::factory()->create([
        'cashback_reward_id' => $payout->cashback_reward_id,
        'payout_account_id' => $payout->payout_account_id,
    ]))->toThrow(QueryException::class)
        ->and(Payout::query()->where('cashback_reward_id', $payout->cashback_reward_id)->count())
        ->toBe(1);
});

it('enforces payout invariants in postgres', function (array $invalid): void {
    $payout = Payout::factory()->create();

    expect(fn () => DB::table('payouts')->where('id', $payout->id)->update($invalid))
        ->toThrow(QueryException::class);
})->with([
    'unknown provider' => [['provider' => 'unknown']],
    'non-positive amount' => [['amount_minor' => 0]],
    'unsupported currency' => [['currency' => 'USD']],
    'unknown factual state' => [['status' => 'lost']],
    'started payout cannot be completed' => [['completed_at' => now()]],
    'pending requires transfer code and completion' => [[
        'status' => 'pending',
        'completed_at' => now(),
    ]],
    'permanent rejection cannot have transfer code' => [[
        'status' => 'permanent_rejection',
        'provider_transfer_code' => 'TRF_impossible',
        'completed_at' => now(),
    ]],
    'ambiguous payout cannot have an empty transfer code' => [[
        'status' => 'ambiguous',
        'provider_transfer_code' => '',
        'completed_at' => now(),
    ]],
    'success requires a success timestamp' => [[
        'status' => 'succeeded',
        'provider_transfer_code' => 'TRF_missing_time',
        'completed_at' => now(),
    ]],
    'reversal requires a reversal timestamp' => [[
        'status' => 'reversed',
        'provider_transfer_code' => 'TRF_missing_time',
        'completed_at' => now(),
    ]],
    'HTTP status below range' => [['provider_http_status' => 99]],
    'HTTP status above range' => [['provider_http_status' => 600]],
    'negative latency' => [['provider_latency_ms' => -1]],
    'negative observed balance' => [['observed_balance_minor' => -1]],
]);

it('enforces reward provider and balance observation invariants in postgres', function (array $invalid): void {
    $reward = CashbackReward::factory()->create();

    expect(fn () => DB::table('cashback_rewards')->where('id', $reward->id)->update($invalid))
        ->toThrow(QueryException::class);
})->with([
    'unknown provider' => [['provider' => 'unknown']],
    'negative observed balance' => [['last_observed_balance_minor' => -1]],
    'balance without observation time' => [['last_observed_balance_minor' => 0]],
    'observation time without balance' => [['balance_observed_at' => now()]],
]);

it('preserves payouts by restricting deletion of their reward and payout account', function (): void {
    $payout = Payout::factory()->create();

    expect(fn () => $payout->cashbackReward->delete())->toThrow(QueryException::class)
        ->and(fn () => $payout->payoutAccount->delete())->toThrow(QueryException::class)
        ->and(Payout::query()->whereKey($payout->id)->exists())->toBeTrue();
});

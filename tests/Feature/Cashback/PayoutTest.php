<?php

declare(strict_types=1);

use App\Enums\CashbackRewardStatus;
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

it('stores provider balance observations only on the payout', function (): void {
    $observedAt = CarbonImmutable::parse('2026-08-23T01:30:00Z');
    $reward = CashbackReward::factory()->create([
        'status' => CashbackRewardStatus::AwaitingFunds,
    ]);
    $payout = Payout::factory()->create([
        'cashback_reward_id' => $reward->id,
        'status' => PayoutStatus::InsufficientFunds,
        'provider_error_code' => 'insufficient_funds',
        'observed_balance_minor' => 0,
        'balance_observed_at' => $observedAt,
        'started_at' => $observedAt->subSecond(),
        'first_result_at' => $observedAt,
    ]);

    expect(Schema::getColumnListing('cashback_rewards'))->not->toContain(
        'provider',
        'last_attempted_at',
        'last_error_code',
        'last_error_message',
        'last_observed_balance_minor',
        'balance_observed_at',
    )
        ->and(Schema::getColumnListing('payouts'))->toContain(
            'observed_balance_minor',
            'balance_observed_at',
            'first_result_at',
        )
        ->not->toContain('completed_at')
        ->and($payout->observed_balance_minor)->toBe(0)
        ->and($payout->balance_observed_at?->equalTo($observedAt))->toBeTrue()
        ->and($payout->first_result_at?->equalTo($observedAt))->toBeTrue();
});

it('creates a coherent durable payout with typed relationships and casts', function (): void {
    $payout = Payout::factory()->create();
    $reward = $payout->cashbackReward;
    $account = $payout->payoutAccount;

    expect($reward->status)->toBe(CashbackRewardStatus::Processing)
        ->and($payout->provider)->toBe($account->provider)
        ->and($payout->provider_reference)->toBe($reward->provider_reference)
        ->and($payout->provider_recipient_code)->toBe($account->provider_recipient_code)
        ->and($payout->amount_minor)->toBe($reward->amount_minor)
        ->and($payout->currency)->toBe($reward->currency)
        ->and($payout->status)->toBe(PayoutStatus::Started)
        ->and($payout->started_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($payout->balance_observed_at)->toBeNull()
        ->and($payout->first_result_at)->toBeNull()
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

it('accepts only the matching rate limited payout status and error code', function (): void {
    $reward = CashbackReward::factory()->create([
        'status' => CashbackRewardStatus::RequiresAttention,
    ]);
    $payout = Payout::factory()->create([
        'cashback_reward_id' => $reward->id,
        'status' => PayoutStatus::RateLimited,
        'provider_error_code' => 'rate_limited',
        'first_result_at' => now(),
    ]);

    expect($payout->status)->toBe(PayoutStatus::RateLimited)
        ->and($payout->provider_error_code)->toBe('rate_limited');
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
    'started payout cannot have a first result' => [['first_result_at' => now()]],
    'non-started payout requires a first result' => [[
        'status' => 'rejected',
    ]],
    'pending requires transfer code' => [[
        'status' => 'pending',
        'first_result_at' => now(),
    ]],
    'rejected payout cannot have transfer code' => [[
        'status' => 'rejected',
        'provider_transfer_code' => 'TRF_impossible',
        'first_result_at' => now(),
    ]],
    'ambiguous payout cannot have an empty transfer code' => [[
        'status' => 'ambiguous',
        'provider_transfer_code' => '',
        'first_result_at' => now(),
    ]],
    'success requires a success timestamp' => [[
        'status' => 'succeeded',
        'provider_transfer_code' => 'TRF_missing_time',
        'first_result_at' => now(),
    ]],
    'reversal requires a reversal timestamp' => [[
        'status' => 'reversed',
        'provider_transfer_code' => 'TRF_missing_time',
        'first_result_at' => now(),
    ]],
    'rate limited status requires the matching error code' => [[
        'status' => 'rate_limited',
        'first_result_at' => now(),
    ]],
    'rate limited error code requires the matching status' => [[
        'status' => 'rejected',
        'provider_error_code' => 'rate_limited',
        'first_result_at' => now(),
    ]],
    'HTTP status below range' => [['provider_http_status' => 99]],
    'HTTP status above range' => [['provider_http_status' => 600]],
    'negative latency' => [['provider_latency_ms' => -1]],
    'negative observed balance' => [['observed_balance_minor' => -1]],
    'balance without observation time' => [['observed_balance_minor' => 0]],
    'observation time without balance' => [['balance_observed_at' => now()]],
]);

it('rejects removed or unknown cashback reward states in postgres', function (string $status): void {
    $reward = CashbackReward::factory()->create();

    expect(fn () => DB::table('cashback_rewards')->where('id', $reward->id)->update([
        'status' => $status,
    ]))
        ->toThrow(QueryException::class);
})->with([
    'removed retry state' => 'retrying',
    'unknown state' => 'lost',
]);

it('preserves payouts by restricting deletion of their reward and payout account', function (): void {
    $payout = Payout::factory()->create();

    expect(fn () => $payout->cashbackReward->delete())->toThrow(QueryException::class)
        ->and(fn () => $payout->payoutAccount->delete())->toThrow(QueryException::class)
        ->and(Payout::query()->whereKey($payout->id)->exists())->toBeTrue();
});

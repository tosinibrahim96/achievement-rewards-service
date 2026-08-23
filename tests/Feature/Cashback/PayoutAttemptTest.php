<?php

declare(strict_types=1);

use App\Enums\CashbackRewardStatus;
use App\Enums\PaymentProvider;
use App\Enums\PayoutAttemptStatus;
use App\Models\CashbackReward;
use App\Models\PayoutAttempt;
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

it('creates a coherent durable attempt snapshot with typed relationships and casts', function (): void {
    $attempt = PayoutAttempt::factory()->create();
    $reward = $attempt->cashbackReward;
    $account = $attempt->payoutAccount;

    expect($reward->status)->toBe(CashbackRewardStatus::Processing)
        ->and($reward->provider)->toBe(PaymentProvider::Fake)
        ->and($attempt->attempt_number)->toBe(1)
        ->and($attempt->provider)->toBe($account->provider)
        ->and($attempt->provider_reference)->toBe($reward->provider_reference)
        ->and($attempt->provider_recipient_code)->toBe($account->provider_recipient_code)
        ->and($attempt->amount_minor)->toBe($reward->amount_minor)
        ->and($attempt->currency)->toBe($reward->currency)
        ->and($attempt->status)->toBe(PayoutAttemptStatus::Started)
        ->and($attempt->started_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($attempt->completed_at)->toBeNull()
        ->and($attempt->succeeded_at)->toBeNull()
        ->and($attempt->reversed_at)->toBeNull()
        ->and($attempt->support_alert_requested_at)->toBeNull()
        ->and($reward->payoutAttempts()->firstOrFail()->is($attempt))->toBeTrue()
        ->and($account->payoutAttempts()->firstOrFail()->is($attempt))->toBeTrue();
});

it('casts the support-request intent timestamp without claiming delivery', function (): void {
    $attempt = PayoutAttempt::factory()->create([
        'support_alert_requested_at' => '2026-08-23T18:30:00+00:00',
    ]);

    expect($attempt->support_alert_requested_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($attempt->support_alert_requested_at?->toIso8601String())
        ->toBe('2026-08-23T18:30:00+00:00');
});

it('enforces one factual attempt number per reward', function (): void {
    $attempt = PayoutAttempt::factory()->create();

    expect(fn () => PayoutAttempt::factory()->create([
        'cashback_reward_id' => $attempt->cashback_reward_id,
        'payout_account_id' => $attempt->payout_account_id,
        'attempt_number' => $attempt->attempt_number,
    ]))->toThrow(QueryException::class);
});

it('enforces payout attempt invariants in postgres', function (array $invalid): void {
    $attempt = PayoutAttempt::factory()->create();

    expect(fn () => DB::table('payout_attempts')->where('id', $attempt->id)->update($invalid))
        ->toThrow(QueryException::class);
})->with([
    'non-positive attempt number' => [['attempt_number' => 0]],
    'unknown provider' => [['provider' => 'unknown']],
    'non-positive amount' => [['amount_minor' => 0]],
    'unsupported currency' => [['currency' => 'USD']],
    'unknown factual state' => [['status' => 'lost']],
    'started attempt cannot be completed' => [['completed_at' => now()]],
    'pending requires transfer code and completion' => [[
        'status' => 'pending',
        'completed_at' => now(),
    ]],
    'pre-creation rejection cannot have transfer code' => [[
        'status' => 'permanent_rejection',
        'provider_transfer_code' => 'TRF_impossible',
        'completed_at' => now(),
    ]],
    'ambiguous attempt cannot have an empty transfer code' => [[
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

it('preserves attempts by restricting deletion of their reward and payout account', function (): void {
    $attempt = PayoutAttempt::factory()->create();

    expect(fn () => $attempt->cashbackReward->delete())->toThrow(QueryException::class)
        ->and(fn () => $attempt->payoutAccount->delete())->toThrow(QueryException::class)
        ->and(PayoutAttempt::query()->whereKey($attempt->id)->exists())->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Enums\CashbackRewardStatus;
use App\Enums\PaymentProvider;
use App\Enums\PayoutStatus;
use App\Models\CashbackReward;
use App\Models\Payout;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseMigrations::class);

it('changes the empty payout schema and can roll it back and forward', function (): void {
    $migration = payoutResultCleanupMigration();

    runPayoutResultCleanupDown($migration);

    expect(Schema::getColumnListing('cashback_rewards'))->toContain(
        'provider',
        'last_attempted_at',
        'last_error_code',
        'last_error_message',
        'last_observed_balance_minor',
        'balance_observed_at',
    )
        ->and(Schema::getColumnListing('payouts'))->toContain('completed_at')
        ->not->toContain('first_result_at', 'balance_observed_at');

    runPayoutResultCleanupUp($migration);

    expect(Schema::getColumnListing('cashback_rewards'))->not->toContain(
        'provider',
        'last_attempted_at',
        'last_error_code',
        'last_error_message',
        'last_observed_balance_minor',
        'balance_observed_at',
    )
        ->and(Schema::getColumnListing('payouts'))->toContain(
            'first_result_at',
            'balance_observed_at',
        )
        ->not->toContain('completed_at');
});

it('refuses to discard existing cashback reward history', function (): void {
    $migration = payoutResultCleanupMigration();
    runPayoutResultCleanupDown($migration);
    $reward = CashbackReward::factory()->create();

    try {
        expect(fn () => runPayoutResultCleanupUp($migration))->toThrow(
            RuntimeException::class,
            'Payout result cleanup requires empty cashback reward and payout tables.',
        );

        expect(Schema::hasColumn('cashback_rewards', 'provider'))->toBeTrue()
            ->and(Schema::hasColumn('payouts', 'completed_at'))->toBeTrue()
            ->and(Schema::hasColumn('payouts', 'first_result_at'))->toBeFalse();
    } finally {
        DB::table('cashback_rewards')->where('id', $reward->id)->delete();
        runPayoutResultCleanupUp($migration);
    }
});

it('maps final payout names when the test suite rolls migrations back', function (
    PayoutStatus $status,
    string $errorCode,
    string $oldStatus,
): void {
    $firstResultAt = CarbonImmutable::now()->startOfSecond();
    $startedAt = $firstResultAt->subSecond();
    $reward = CashbackReward::factory()->create([
        'status' => CashbackRewardStatus::RequiresAttention,
    ]);
    $payout = Payout::factory()->create([
        'cashback_reward_id' => $reward->id,
        'status' => $status,
        'provider_error_code' => $errorCode,
        'provider_error_message' => 'A safe provider result.',
        'observed_balance_minor' => 123,
        'balance_observed_at' => $firstResultAt,
        'started_at' => $startedAt,
        'first_result_at' => $firstResultAt,
    ]);
    $migration = payoutResultCleanupMigration();
    $rolledBack = false;

    try {
        runPayoutResultCleanupDown($migration);
        $rolledBack = true;

        $oldPayout = DB::table('payouts')->where('id', $payout->id)->sole();
        $oldReward = DB::table('cashback_rewards')->where('id', $reward->id)->sole();

        expect($oldPayout->status)->toBe($oldStatus)
            ->and(CarbonImmutable::parse($oldPayout->completed_at)->equalTo($firstResultAt))
            ->toBeTrue()
            ->and($oldReward->provider)->toBe(PaymentProvider::Fake->value)
            ->and(CarbonImmutable::parse($oldReward->last_attempted_at)->equalTo($startedAt))
            ->toBeTrue()
            ->and($oldReward->last_error_code)->toBe($errorCode)
            ->and($oldReward->last_error_message)->toBe('A safe provider result.')
            ->and($oldReward->last_observed_balance_minor)->toBe(123)
            ->and(CarbonImmutable::parse($oldReward->balance_observed_at)->equalTo($firstResultAt))
            ->toBeTrue();
    } finally {
        if ($rolledBack) {
            DB::table('payouts')->where('id', $payout->id)->delete();
            DB::table('cashback_rewards')->where('id', $reward->id)->delete();
            runPayoutResultCleanupUp($migration);
        }
    }
})->with([
    'rate limited' => [PayoutStatus::RateLimited, 'rate_limited', 'retryable_rejection'],
    'rejected' => [PayoutStatus::Rejected, 'permanent_failure', 'permanent_rejection'],
]);

function payoutResultCleanupMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path(
        'migrations/2026_08_24_110000_cleanup_payout_results_and_provider_facts.php',
    );

    return $migration;
}

function runPayoutResultCleanupUp(Migration $migration): void
{
    DB::transaction(static function () use ($migration): void {
        $migration->up();
    });
}

function runPayoutResultCleanupDown(Migration $migration): void
{
    DB::transaction(static function () use ($migration): void {
        $migration->down();
    });
}

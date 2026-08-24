<?php

declare(strict_types=1);

use App\Enums\CashbackRewardStatus;
use App\Models\CashbackReward;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

it('restores ready rewards to the old status on rollback and allows reapply', function (): void {
    $user = User::factory()->create();
    PayoutAccount::factory()->for($user)->create();
    $reward = CashbackReward::factory()
        ->for($user)
        ->for(UserBadge::factory()->for($user), 'userBadge')
        ->readyForPayout()
        ->create();
    $before = (array) DB::table('cashback_rewards')->where('id', $reward->id)->sole();

    expect(fn () => DB::table('cashback_rewards')->where('id', $reward->id)->update([
        'status' => 'unknown_readiness',
    ]))->toThrow(QueryException::class);

    /** @var Migration $migration */
    $migration = require database_path(
        'migrations/2026_08_24_090000_add_ready_for_payout_to_cashback_reward_statuses.php',
    );
    $migration->down();

    try {
        $rolledBack = (array) DB::table('cashback_rewards')->where('id', $reward->id)->sole();

        expect($rolledBack['status'])->toBe(CashbackRewardStatus::AwaitingPayoutAccount->value)
            ->and($rolledBack['created_at'])->toBe($before['created_at'])
            ->and($rolledBack['updated_at'])->toBe($before['updated_at'])
            ->and(fn () => DB::table('cashback_rewards')->where('id', $reward->id)->update([
                'status' => CashbackRewardStatus::ReadyForPayout->value,
            ]))->toThrow(QueryException::class);
    } finally {
        $migration->up();
    }

    expect(DB::table('cashback_rewards')->where('id', $reward->id)->update([
        'status' => CashbackRewardStatus::ReadyForPayout->value,
    ]))->toBe(1);
});

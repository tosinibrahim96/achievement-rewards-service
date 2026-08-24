<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->lockPayoutTables();

        if (DB::table('cashback_rewards')->exists() || DB::table('payouts')->exists()) {
            throw new RuntimeException(
                'Payout result cleanup requires empty cashback reward and payout tables.',
            );
        }

        Schema::table('payouts', function (Blueprint $table): void {
            $table->timestampTz('balance_observed_at')->nullable();
        });
        DB::statement('ALTER TABLE payouts ADD CONSTRAINT payouts_balance_observation_pair_check CHECK ((observed_balance_minor IS NULL AND balance_observed_at IS NULL) OR (observed_balance_minor IS NOT NULL AND balance_observed_at IS NOT NULL))');

        DB::statement('ALTER TABLE cashback_rewards DROP CONSTRAINT cashback_rewards_provider_check');
        DB::statement('ALTER TABLE cashback_rewards DROP CONSTRAINT cashback_rewards_last_observed_balance_minor_check');
        DB::statement('ALTER TABLE cashback_rewards DROP CONSTRAINT cashback_rewards_balance_observation_pair_check');
        DB::statement('ALTER TABLE cashback_rewards DROP CONSTRAINT cashback_rewards_status_check');
        DB::statement("ALTER TABLE cashback_rewards ADD CONSTRAINT cashback_rewards_status_check CHECK (status IN ('awaiting_payout_account', 'ready_for_payout', 'awaiting_funds', 'pending', 'processing', 'paid', 'requires_attention'))");

        Schema::table('cashback_rewards', function (Blueprint $table): void {
            $table->dropColumn([
                'provider',
                'last_attempted_at',
                'last_error_code',
                'last_error_message',
                'last_observed_balance_minor',
                'balance_observed_at',
            ]);
        });

        Schema::table('payouts', function (Blueprint $table): void {
            $table->renameColumn('completed_at', 'first_result_at');
        });
        DB::statement('ALTER TABLE payouts RENAME CONSTRAINT payouts_completion_check TO payouts_first_result_at_check');

        DB::statement('ALTER TABLE payouts DROP CONSTRAINT payouts_status_check');
        DB::statement('ALTER TABLE payouts DROP CONSTRAINT payouts_transfer_code_check');
        DB::statement("ALTER TABLE payouts ADD CONSTRAINT payouts_status_check CHECK (status IN ('started', 'ambiguous', 'pending', 'succeeded', 'insufficient_funds', 'rate_limited', 'rejected', 'otp_required', 'failed', 'reversed'))");
        DB::statement("ALTER TABLE payouts ADD CONSTRAINT payouts_transfer_code_check CHECK ((status IN ('pending', 'succeeded', 'otp_required', 'failed', 'reversed') AND provider_transfer_code IS NOT NULL AND provider_transfer_code <> '') OR (status IN ('started', 'insufficient_funds', 'rate_limited', 'rejected') AND provider_transfer_code IS NULL) OR (status = 'ambiguous' AND (provider_transfer_code IS NULL OR provider_transfer_code <> '')))");
        DB::statement("ALTER TABLE payouts ADD CONSTRAINT payouts_rate_limited_error_check CHECK ((status = 'rate_limited') = (provider_error_code IS NOT DISTINCT FROM 'rate_limited'))");
    }

    public function down(): void
    {
        $this->lockPayoutTables();

        Schema::table('cashback_rewards', function (Blueprint $table): void {
            $table->string('provider')->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->unsignedBigInteger('last_observed_balance_minor')->nullable();
            $table->timestampTz('balance_observed_at')->nullable();
        });

        DB::statement("ALTER TABLE cashback_rewards ADD CONSTRAINT cashback_rewards_provider_check CHECK (provider IN ('fake', 'paystack'))");
        DB::statement('ALTER TABLE cashback_rewards ADD CONSTRAINT cashback_rewards_last_observed_balance_minor_check CHECK (last_observed_balance_minor IS NULL OR last_observed_balance_minor >= 0)');
        DB::statement('ALTER TABLE cashback_rewards ADD CONSTRAINT cashback_rewards_balance_observation_pair_check CHECK ((last_observed_balance_minor IS NULL AND balance_observed_at IS NULL) OR (last_observed_balance_minor IS NOT NULL AND balance_observed_at IS NOT NULL))');
        DB::statement(<<<'SQL'
            UPDATE cashback_rewards
            SET provider = payouts.provider,
                last_attempted_at = payouts.started_at,
                last_error_code = payouts.provider_error_code,
                last_error_message = payouts.provider_error_message,
                last_observed_balance_minor = payouts.observed_balance_minor,
                balance_observed_at = payouts.balance_observed_at
            FROM payouts
            WHERE payouts.cashback_reward_id = cashback_rewards.id
            SQL);

        DB::statement('ALTER TABLE payouts DROP CONSTRAINT payouts_balance_observation_pair_check');
        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropColumn('balance_observed_at');
        });

        DB::statement('ALTER TABLE cashback_rewards DROP CONSTRAINT cashback_rewards_status_check');
        DB::statement("ALTER TABLE cashback_rewards ADD CONSTRAINT cashback_rewards_status_check CHECK (status IN ('awaiting_payout_account', 'ready_for_payout', 'awaiting_funds', 'pending', 'processing', 'retrying', 'paid', 'requires_attention'))");

        DB::statement('ALTER TABLE payouts DROP CONSTRAINT payouts_rate_limited_error_check');
        DB::statement('ALTER TABLE payouts DROP CONSTRAINT payouts_status_check');
        DB::statement('ALTER TABLE payouts DROP CONSTRAINT payouts_transfer_code_check');
        DB::statement(<<<'SQL'
            UPDATE payouts
            SET status = CASE status
                WHEN 'rate_limited' THEN 'retryable_rejection'
                WHEN 'rejected' THEN 'permanent_rejection'
                ELSE status
            END
            WHERE status IN ('rate_limited', 'rejected')
            SQL);
        DB::statement("ALTER TABLE payouts ADD CONSTRAINT payouts_status_check CHECK (status IN ('started', 'ambiguous', 'pending', 'succeeded', 'insufficient_funds', 'retryable_rejection', 'permanent_rejection', 'otp_required', 'failed', 'reversed'))");
        DB::statement("ALTER TABLE payouts ADD CONSTRAINT payouts_transfer_code_check CHECK ((status IN ('pending', 'succeeded', 'otp_required', 'failed', 'reversed') AND provider_transfer_code IS NOT NULL AND provider_transfer_code <> '') OR (status IN ('started', 'insufficient_funds', 'retryable_rejection', 'permanent_rejection') AND provider_transfer_code IS NULL) OR (status = 'ambiguous' AND (provider_transfer_code IS NULL OR provider_transfer_code <> '')))");

        Schema::table('payouts', function (Blueprint $table): void {
            $table->renameColumn('first_result_at', 'completed_at');
        });
        DB::statement('ALTER TABLE payouts RENAME CONSTRAINT payouts_first_result_at_check TO payouts_completion_check');
    }

    private function lockPayoutTables(): void
    {
        DB::statement("SET LOCAL lock_timeout = '10s'");
        DB::statement('LOCK TABLE cashback_rewards, payouts IN ACCESS EXCLUSIVE MODE');
    }
};

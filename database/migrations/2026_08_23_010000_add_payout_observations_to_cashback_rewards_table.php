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
        Schema::table('cashback_rewards', function (Blueprint $table): void {
            $table->string('provider')->nullable();
            $table->unsignedBigInteger('last_observed_balance_minor')->nullable();
            $table->timestampTz('balance_observed_at')->nullable();
        });

        DB::statement("ALTER TABLE cashback_rewards ADD CONSTRAINT cashback_rewards_provider_check CHECK (provider IN ('fake', 'paystack'))");
        DB::statement('ALTER TABLE cashback_rewards ADD CONSTRAINT cashback_rewards_last_observed_balance_minor_check CHECK (last_observed_balance_minor IS NULL OR last_observed_balance_minor >= 0)');
        DB::statement('ALTER TABLE cashback_rewards ADD CONSTRAINT cashback_rewards_balance_observation_pair_check CHECK ((last_observed_balance_minor IS NULL AND balance_observed_at IS NULL) OR (last_observed_balance_minor IS NOT NULL AND balance_observed_at IS NOT NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cashback_rewards DROP CONSTRAINT cashback_rewards_provider_check');
        DB::statement('ALTER TABLE cashback_rewards DROP CONSTRAINT cashback_rewards_last_observed_balance_minor_check');
        DB::statement('ALTER TABLE cashback_rewards DROP CONSTRAINT cashback_rewards_balance_observation_pair_check');

        Schema::table('cashback_rewards', function (Blueprint $table): void {
            $table->dropColumn([
                'provider',
                'last_observed_balance_minor',
                'balance_observed_at',
            ]);
        });
    }
};

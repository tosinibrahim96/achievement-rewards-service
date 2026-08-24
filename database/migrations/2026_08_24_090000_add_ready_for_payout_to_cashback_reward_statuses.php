<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE cashback_rewards DROP CONSTRAINT cashback_rewards_status_check');
        DB::statement("ALTER TABLE cashback_rewards ADD CONSTRAINT cashback_rewards_status_check CHECK (status IN ('awaiting_payout_account', 'ready_for_payout', 'awaiting_funds', 'pending', 'processing', 'retrying', 'paid', 'requires_attention'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cashback_rewards DROP CONSTRAINT cashback_rewards_status_check');
        DB::table('cashback_rewards')
            ->where('status', 'ready_for_payout')
            ->update(['status' => 'awaiting_payout_account']);
        DB::statement("ALTER TABLE cashback_rewards ADD CONSTRAINT cashback_rewards_status_check CHECK (status IN ('awaiting_payout_account', 'awaiting_funds', 'pending', 'processing', 'retrying', 'paid', 'requires_attention'))");
    }
};

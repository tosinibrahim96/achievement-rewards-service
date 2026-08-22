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
        Schema::create('cashback_rewards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_badge_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('provider_reference')->unique();
            $table->string('status');
            $table->ulid('correlation_id');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('correlation_id');
        });

        DB::statement('ALTER TABLE cashback_rewards ADD CONSTRAINT cashback_rewards_amount_minor_check CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE cashback_rewards ADD CONSTRAINT cashback_rewards_currency_check CHECK (currency = 'NGN')");
        DB::statement("ALTER TABLE cashback_rewards ADD CONSTRAINT cashback_rewards_status_check CHECK (status IN ('awaiting_payout_account', 'awaiting_funds', 'pending', 'processing', 'retrying', 'paid', 'requires_attention'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('cashback_rewards');
    }
};

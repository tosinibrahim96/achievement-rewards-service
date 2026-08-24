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
        Schema::drop('payout_attempts');
    }

    public function down(): void
    {
        Schema::create('payout_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cashback_reward_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->foreignId('payout_account_id')->constrained()->restrictOnDelete();
            $table->string('provider');
            $table->string('provider_reference');
            $table->string('provider_recipient_code');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status');
            $table->string('provider_transfer_code')->nullable();
            $table->unsignedSmallInteger('provider_http_status')->nullable();
            $table->string('provider_error_code')->nullable();
            $table->text('provider_error_message')->nullable();
            $table->unsignedInteger('provider_latency_ms')->nullable();
            $table->unsignedBigInteger('observed_balance_minor')->nullable();
            $table->timestampTz('succeeded_at')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->timestampTz('support_alert_requested_at')->nullable();

            $table->unique(
                ['cashback_reward_id', 'attempt_number'],
                'payout_attempts_reward_number_unique',
            );
            $table->index(['provider', 'provider_reference']);
            $table->index(['provider', 'provider_transfer_code']);
        });

        DB::statement('ALTER TABLE payout_attempts ADD CONSTRAINT payout_attempts_attempt_number_check CHECK (attempt_number > 0)');
        DB::statement("ALTER TABLE payout_attempts ADD CONSTRAINT payout_attempts_provider_check CHECK (provider IN ('fake', 'paystack'))");
        DB::statement('ALTER TABLE payout_attempts ADD CONSTRAINT payout_attempts_amount_minor_check CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE payout_attempts ADD CONSTRAINT payout_attempts_currency_check CHECK (currency = 'NGN')");
        DB::statement("ALTER TABLE payout_attempts ADD CONSTRAINT payout_attempts_status_check CHECK (status IN ('started', 'ambiguous', 'pending', 'succeeded', 'insufficient_funds', 'retryable_rejection', 'permanent_rejection', 'otp_required', 'failed', 'reversed'))");
        DB::statement("ALTER TABLE payout_attempts ADD CONSTRAINT payout_attempts_transfer_code_check CHECK ((status IN ('pending', 'succeeded', 'otp_required', 'failed', 'reversed') AND provider_transfer_code IS NOT NULL AND provider_transfer_code <> '') OR (status IN ('started', 'insufficient_funds', 'retryable_rejection', 'permanent_rejection') AND provider_transfer_code IS NULL) OR (status = 'ambiguous' AND (provider_transfer_code IS NULL OR provider_transfer_code <> '')))");
        DB::statement("ALTER TABLE payout_attempts ADD CONSTRAINT payout_attempts_completion_check CHECK ((status = 'started' AND completed_at IS NULL) OR (status <> 'started' AND completed_at IS NOT NULL))");
        DB::statement("ALTER TABLE payout_attempts ADD CONSTRAINT payout_attempts_succeeded_at_check CHECK ((status = 'succeeded' AND succeeded_at IS NOT NULL) OR (status = 'reversed') OR (status NOT IN ('succeeded', 'reversed') AND succeeded_at IS NULL))");
        DB::statement("ALTER TABLE payout_attempts ADD CONSTRAINT payout_attempts_reversed_at_check CHECK ((status = 'reversed' AND reversed_at IS NOT NULL) OR (status <> 'reversed' AND reversed_at IS NULL))");
        DB::statement('ALTER TABLE payout_attempts ADD CONSTRAINT payout_attempts_http_status_check CHECK (provider_http_status IS NULL OR provider_http_status BETWEEN 100 AND 599)');
        DB::statement('ALTER TABLE payout_attempts ADD CONSTRAINT payout_attempts_latency_check CHECK (provider_latency_ms IS NULL OR provider_latency_ms >= 0)');
        DB::statement('ALTER TABLE payout_attempts ADD CONSTRAINT payout_attempts_observed_balance_check CHECK (observed_balance_minor IS NULL OR observed_balance_minor >= 0)');
    }
};

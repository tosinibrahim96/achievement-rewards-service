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
        Schema::create('provider_webhook_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('body_hash', 64);
            $table->string('event_type', 100)->nullable();
            $table->string('provider_reference', 255)->nullable();
            $table->foreignId('payout_attempt_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
            $table->string('result', 32);
            $table->timestampTz('received_at');

            $table->unique(
                ['provider', 'body_hash'],
                'provider_webhook_receipts_provider_body_hash_unique',
            );
        });

        DB::statement("ALTER TABLE provider_webhook_receipts ADD CONSTRAINT provider_webhook_receipts_provider_check CHECK (provider = 'paystack')");
        DB::statement("ALTER TABLE provider_webhook_receipts ADD CONSTRAINT provider_webhook_receipts_body_hash_check CHECK (body_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE provider_webhook_receipts ADD CONSTRAINT provider_webhook_receipts_result_check CHECK (result IN ('applied', 'unchanged', 'invalid', 'unsupported', 'not_found', 'mismatch'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_webhook_receipts');
    }
};

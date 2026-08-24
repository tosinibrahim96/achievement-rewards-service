<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_webhook_receipts', function (Blueprint $table): void {
            $table->foreignId('payout_id')
                ->nullable()
                ->constrained('payouts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('provider_webhook_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payout_id');
        });
    }
};

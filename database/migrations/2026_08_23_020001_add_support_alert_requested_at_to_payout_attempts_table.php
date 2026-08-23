<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_attempts', function (Blueprint $table): void {
            $table->timestampTz('support_alert_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payout_attempts', function (Blueprint $table): void {
            $table->dropColumn('support_alert_requested_at');
        });
    }
};

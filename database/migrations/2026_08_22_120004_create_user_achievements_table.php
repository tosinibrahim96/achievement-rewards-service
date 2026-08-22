<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_achievements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('achievement_id')->constrained()->restrictOnDelete();
            $table->foreignId('triggered_by_purchase_id')->nullable()->constrained('purchases')->restrictOnDelete();
            $table->ulid('correlation_id');
            $table->timestampTz('unlocked_at');
            $table->timestamps();

            $table->unique(['user_id', 'achievement_id']);
            $table->index(['user_id', 'unlocked_at']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_achievements');
    }
};

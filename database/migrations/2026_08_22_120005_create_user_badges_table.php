<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_badges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('badge_id')->constrained()->restrictOnDelete();
            $table->foreignId('triggered_by_user_achievement_id')
                ->nullable()
                ->constrained('user_achievements')
                ->restrictOnDelete();
            $table->ulid('correlation_id');
            $table->timestampTz('unlocked_at');
            $table->timestamps();

            $table->unique(['user_id', 'badge_id']);
            $table->index(['user_id', 'unlocked_at']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_badges');
    }
};

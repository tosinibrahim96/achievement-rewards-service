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
        Schema::create('achievements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('achievement_group_id')->constrained()->restrictOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedBigInteger('threshold');
            $table->smallInteger('sort_order');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['achievement_group_id', 'threshold']);
            $table->index(['achievement_group_id', 'is_active', 'threshold']);
        });

        DB::statement('ALTER TABLE achievements ADD CONSTRAINT achievements_threshold_check CHECK (threshold > 0)');
        DB::statement('ALTER TABLE achievements ADD CONSTRAINT achievements_sort_order_check CHECK (sort_order > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};

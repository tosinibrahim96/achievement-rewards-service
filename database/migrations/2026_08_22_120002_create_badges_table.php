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
        Schema::create('badges', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('required_achievement_count')->unique();
            $table->unsignedSmallInteger('rank')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE badges ADD CONSTRAINT badges_required_achievement_count_check CHECK (required_achievement_count > 0)');
        DB::statement('ALTER TABLE badges ADD CONSTRAINT badges_rank_check CHECK (rank > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('badges');
    }
};

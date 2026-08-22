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
        Schema::create('achievement_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('metric');
            $table->smallInteger('sort_order');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        DB::statement("ALTER TABLE achievement_groups ADD CONSTRAINT achievement_groups_metric_check CHECK (metric IN ('purchase_count', 'lifetime_spend'))");
        DB::statement('ALTER TABLE achievement_groups ADD CONSTRAINT achievement_groups_sort_order_check CHECK (sort_order > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('achievement_groups');
    }
};

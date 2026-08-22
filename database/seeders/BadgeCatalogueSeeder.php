<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

class BadgeCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $badges = [
            ['code' => 'beginner', 'name' => 'Beginner', 'required_achievement_count' => 1, 'rank' => 1],
            ['code' => 'intermediate', 'name' => 'Intermediate', 'required_achievement_count' => 4, 'rank' => 2],
            ['code' => 'advanced', 'name' => 'Advanced', 'required_achievement_count' => 8, 'rank' => 3],
            ['code' => 'master', 'name' => 'Master', 'required_achievement_count' => 10, 'rank' => 4],
        ];

        foreach ($badges as $badgeDefinition) {
            Badge::query()->updateOrCreate(
                ['code' => $badgeDefinition['code']],
                [...$badgeDefinition, 'is_active' => true],
            );
        }
    }
}

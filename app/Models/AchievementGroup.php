<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AchievementMetric;
use Database\Factories\AchievementGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property AchievementMetric $metric
 * @property int $sort_order
 * @property bool $is_active
 */
#[Fillable(['code', 'name', 'metric', 'sort_order', 'is_active'])]
class AchievementGroup extends Model
{
    /** @use HasFactory<AchievementGroupFactory> */
    use HasFactory;

    /** @return HasMany<Achievement, $this> */
    public function achievements(): HasMany
    {
        return $this->hasMany(Achievement::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metric' => AchievementMetric::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}

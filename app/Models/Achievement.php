<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AchievementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $achievement_group_id
 * @property string $code
 * @property string $name
 * @property int $threshold
 * @property int $sort_order
 * @property bool $is_active
 * @property-read AchievementGroup $group
 */
#[Fillable(['achievement_group_id', 'code', 'name', 'threshold', 'sort_order', 'is_active'])]
class Achievement extends Model
{
    /** @use HasFactory<AchievementFactory> */
    use HasFactory;

    /** @return BelongsTo<AchievementGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AchievementGroup::class, 'achievement_group_id');
    }

    /** @return HasMany<UserAchievement, $this> */
    public function userAchievements(): HasMany
    {
        return $this->hasMany(UserAchievement::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'threshold' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BadgeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $required_achievement_count
 * @property int $rank
 * @property bool $is_active
 */
#[Fillable(['code', 'name', 'required_achievement_count', 'rank', 'is_active'])]
class Badge extends Model
{
    /** @use HasFactory<BadgeFactory> */
    use HasFactory;

    /** @return HasMany<UserBadge, $this> */
    public function userBadges(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'required_achievement_count' => 'integer',
            'rank' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserBadgeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $user_id
 * @property int $badge_id
 * @property int|null $triggered_by_user_achievement_id
 * @property string $correlation_id
 * @property CarbonImmutable $unlocked_at
 * @property-read User $user
 * @property-read Badge $badge
 * @property-read UserAchievement|null $triggeringAchievement
 * @property-read CashbackReward|null $cashbackReward
 */
#[Fillable(['user_id', 'badge_id', 'triggered_by_user_achievement_id', 'correlation_id', 'unlocked_at'])]
class UserBadge extends Model
{
    /** @use HasFactory<UserBadgeFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Badge, $this> */
    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }

    /** @return BelongsTo<UserAchievement, $this> */
    public function triggeringAchievement(): BelongsTo
    {
        return $this->belongsTo(UserAchievement::class, 'triggered_by_user_achievement_id');
    }

    /** @return HasOne<CashbackReward, $this> */
    public function cashbackReward(): HasOne
    {
        return $this->hasOne(CashbackReward::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unlocked_at' => 'immutable_datetime',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserAchievementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $achievement_id
 * @property int|null $triggered_by_purchase_id
 * @property string $correlation_id
 * @property CarbonImmutable $unlocked_at
 * @property-read User $user
 * @property-read Achievement $achievement
 * @property-read Purchase|null $triggeringPurchase
 */
#[Fillable(['user_id', 'achievement_id', 'triggered_by_purchase_id', 'correlation_id', 'unlocked_at'])]
class UserAchievement extends Model
{
    /** @use HasFactory<UserAchievementFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Achievement, $this> */
    public function achievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class);
    }

    /** @return BelongsTo<Purchase, $this> */
    public function triggeringPurchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'triggered_by_purchase_id');
    }

    /** @return HasMany<UserBadge, $this> */
    public function triggeredBadges(): HasMany
    {
        return $this->hasMany(UserBadge::class, 'triggered_by_user_achievement_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unlocked_at' => 'immutable_datetime',
        ];
    }
}

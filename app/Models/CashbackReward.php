<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CashbackRewardStatus;
use App\Enums\Currency;
use Carbon\CarbonImmutable;
use Database\Factories\CashbackRewardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $user_id
 * @property int $user_badge_id
 * @property int $amount_minor
 * @property Currency $currency
 * @property string $provider_reference
 * @property CashbackRewardStatus $status
 * @property string $correlation_id
 * @property CarbonImmutable|null $paid_at
 * @property-read User $user
 * @property-read UserBadge $userBadge
 * @property-read Payout|null $payout
 */
#[Fillable([
    'user_id',
    'user_badge_id',
    'amount_minor',
    'currency',
    'provider_reference',
    'status',
    'correlation_id',
    'paid_at',
])]
class CashbackReward extends Model
{
    /** @use HasFactory<CashbackRewardFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<UserBadge, $this> */
    public function userBadge(): BelongsTo
    {
        return $this->belongsTo(UserBadge::class);
    }

    /** @return HasOne<Payout, $this> */
    public function payout(): HasOne
    {
        return $this->hasOne(Payout::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'currency' => Currency::class,
            'status' => CashbackRewardStatus::class,
            'paid_at' => 'immutable_datetime',
        ];
    }
}

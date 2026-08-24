<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CashbackRewardStatus;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
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
 * @property PaymentProvider|null $provider
 * @property string $provider_reference
 * @property CashbackRewardStatus $status
 * @property string $correlation_id
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $last_attempted_at
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 * @property int|null $last_observed_balance_minor
 * @property CarbonImmutable|null $balance_observed_at
 * @property-read User $user
 * @property-read UserBadge $userBadge
 * @property-read Payout|null $payout
 */
#[Fillable([
    'user_id',
    'user_badge_id',
    'amount_minor',
    'currency',
    'provider',
    'provider_reference',
    'status',
    'correlation_id',
    'paid_at',
    'last_attempted_at',
    'last_error_code',
    'last_error_message',
    'last_observed_balance_minor',
    'balance_observed_at',
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
            'provider' => PaymentProvider::class,
            'status' => CashbackRewardStatus::class,
            'paid_at' => 'immutable_datetime',
            'last_attempted_at' => 'immutable_datetime',
            'last_observed_balance_minor' => 'integer',
            'balance_observed_at' => 'immutable_datetime',
        ];
    }
}

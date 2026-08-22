<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use Carbon\CarbonImmutable;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $external_reference
 * @property int $amount_minor
 * @property Currency $currency
 * @property CarbonImmutable $completed_at
 * @property string $correlation_id
 * @property-read User $user
 */
#[Fillable(['user_id', 'external_reference', 'amount_minor', 'currency', 'completed_at', 'correlation_id'])]
class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<UserAchievement, $this> */
    public function triggeredAchievements(): HasMany
    {
        return $this->hasMany(UserAchievement::class, 'triggered_by_purchase_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'currency' => Currency::class,
            'completed_at' => 'immutable_datetime',
        ];
    }
}

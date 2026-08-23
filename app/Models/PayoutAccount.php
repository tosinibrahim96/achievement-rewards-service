<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\PaymentProvider;
use Carbon\CarbonImmutable;
use Database\Factories\PayoutAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property PaymentProvider $provider
 * @property string $provider_recipient_code
 * @property string $bank_code
 * @property string $bank_name
 * @property string $account_name
 * @property string $account_last_four
 * @property Currency $currency
 * @property CarbonImmutable $verified_at
 * @property-read User $user
 * @property-read Collection<int, PayoutAttempt> $payoutAttempts
 */
#[Fillable([
    'user_id',
    'provider',
    'provider_recipient_code',
    'bank_code',
    'bank_name',
    'account_name',
    'account_last_four',
    'currency',
    'verified_at',
])]
#[Hidden(['user_id', 'provider_recipient_code', 'account_last_four'])]
class PayoutAccount extends Model
{
    /** @use HasFactory<PayoutAccountFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PayoutAttempt, $this> */
    public function payoutAttempts(): HasMany
    {
        return $this->hasMany(PayoutAttempt::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'currency' => Currency::class,
            'verified_at' => 'immutable_datetime',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PayoutStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PayoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $cashback_reward_id
 * @property int $payout_account_id
 * @property PaymentProvider $provider
 * @property string $provider_reference
 * @property string $provider_recipient_code
 * @property int $amount_minor
 * @property Currency $currency
 * @property PayoutStatus $status
 * @property string|null $provider_transfer_code
 * @property int|null $provider_http_status
 * @property string|null $provider_error_code
 * @property string|null $provider_error_message
 * @property int|null $provider_latency_ms
 * @property int|null $observed_balance_minor
 * @property CarbonImmutable|null $balance_observed_at
 * @property CarbonImmutable|null $succeeded_at
 * @property CarbonImmutable|null $reversed_at
 * @property CarbonImmutable|null $support_alert_requested_at
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $first_result_at
 * @property-read CashbackReward $cashbackReward
 * @property-read PayoutAccount $payoutAccount
 */
#[Fillable([
    'cashback_reward_id',
    'payout_account_id',
    'provider',
    'provider_reference',
    'provider_recipient_code',
    'amount_minor',
    'currency',
    'status',
    'provider_transfer_code',
    'provider_http_status',
    'provider_error_code',
    'provider_error_message',
    'provider_latency_ms',
    'observed_balance_minor',
    'balance_observed_at',
    'succeeded_at',
    'reversed_at',
    'support_alert_requested_at',
    'started_at',
    'first_result_at',
])]
class Payout extends Model
{
    /** @use HasFactory<PayoutFactory> */
    use HasFactory;

    /** @return BelongsTo<CashbackReward, $this> */
    public function cashbackReward(): BelongsTo
    {
        return $this->belongsTo(CashbackReward::class);
    }

    /** @return BelongsTo<PayoutAccount, $this> */
    public function payoutAccount(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'amount_minor' => 'integer',
            'currency' => Currency::class,
            'status' => PayoutStatus::class,
            'provider_http_status' => 'integer',
            'provider_latency_ms' => 'integer',
            'observed_balance_minor' => 'integer',
            'balance_observed_at' => 'immutable_datetime',
            'succeeded_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
            'support_alert_requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'first_result_at' => 'immutable_datetime',
        ];
    }
}

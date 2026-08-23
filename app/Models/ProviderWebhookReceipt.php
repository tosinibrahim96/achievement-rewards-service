<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\ProviderWebhookReceiptResult;
use Carbon\CarbonImmutable;
use Database\Factories\ProviderWebhookReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property PaymentProvider $provider
 * @property string $body_hash
 * @property string|null $event_type
 * @property string|null $provider_reference
 * @property int|null $payout_attempt_id
 * @property ProviderWebhookReceiptResult $result
 * @property CarbonImmutable $received_at
 * @property-read PayoutAttempt|null $payoutAttempt
 */
#[Fillable([
    'provider',
    'body_hash',
    'event_type',
    'provider_reference',
    'payout_attempt_id',
    'result',
    'received_at',
])]
#[Hidden(['body_hash', 'provider_reference'])]
class ProviderWebhookReceipt extends Model
{
    /** @use HasFactory<ProviderWebhookReceiptFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<PayoutAttempt, $this> */
    public function payoutAttempt(): BelongsTo
    {
        return $this->belongsTo(PayoutAttempt::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'result' => ProviderWebhookReceiptResult::class,
            'received_at' => 'immutable_datetime',
        ];
    }
}

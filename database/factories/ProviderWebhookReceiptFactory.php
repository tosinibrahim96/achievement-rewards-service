<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentProvider;
use App\Enums\ProviderWebhookReceiptResult;
use App\Models\ProviderWebhookReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProviderWebhookReceipt> */
class ProviderWebhookReceiptFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider' => PaymentProvider::Paystack,
            'body_hash' => hash('sha256', fake()->unique()->uuid()),
            'event_type' => 'transfer.pending',
            'provider_reference' => null,
            'payout_id' => null,
            'result' => ProviderWebhookReceiptResult::Unsupported,
            'received_at' => now(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Data\Purchases\RecordPurchaseResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PurchaseIngestionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var RecordPurchaseResult $result */
        $result = $this->resource;
        $purchase = $result->purchase;

        return [
            'purchase' => [
                'id' => $purchase->id,
                'user_id' => $purchase->user_id,
                'external_reference' => $purchase->external_reference,
                'amount_minor' => $purchase->amount_minor,
                'currency' => $purchase->currency->value,
                'completed_at' => $purchase->completed_at->toISOString(),
            ],
            'was_duplicate' => $result->wasDuplicate,
        ];
    }
}

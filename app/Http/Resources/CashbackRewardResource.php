<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CashbackReward;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class CashbackRewardResource extends JsonResource
{
    /** @return array<string, int|string|null> */
    public function toArray(Request $request): array
    {
        /** @var CashbackReward $cashbackReward */
        $cashbackReward = $this->resource;
        $createdAt = $cashbackReward->created_at
            ?? throw new LogicException('A persisted cashback reward must have a creation timestamp.');
        $updatedAt = $cashbackReward->updated_at
            ?? throw new LogicException('A persisted cashback reward must have an update timestamp.');

        return [
            'id' => $cashbackReward->id,
            'badge_name' => $cashbackReward->userBadge->badge->name,
            'amount_minor' => $cashbackReward->amount_minor,
            'currency' => $cashbackReward->currency->value,
            'status' => $cashbackReward->status->value,
            'created_at' => $createdAt->toImmutable()->utc()->toISOString(),
            'updated_at' => $updatedAt->toImmutable()->utc()->toISOString(),
            'paid_at' => $cashbackReward->paid_at?->utc()->toISOString(),
        ];
    }
}

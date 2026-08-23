<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CashbackReward;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

final class CashbackRewardPageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LengthAwarePaginator<int, CashbackReward> $cashbackRewards */
        $cashbackRewards = $this->resource;

        return [
            'data' => CashbackRewardResource::collection($cashbackRewards->getCollection()),
            'links' => [
                'first' => $cashbackRewards->url(1),
                'last' => $cashbackRewards->url($cashbackRewards->lastPage()),
                'prev' => $cashbackRewards->previousPageUrl(),
                'next' => $cashbackRewards->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $cashbackRewards->currentPage(),
                'per_page' => $cashbackRewards->perPage(),
                'last_page' => $cashbackRewards->lastPage(),
                'total' => $cashbackRewards->total(),
            ],
        ];
    }
}

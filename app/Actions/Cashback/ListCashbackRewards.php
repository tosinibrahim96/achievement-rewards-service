<?php

declare(strict_types=1);

namespace App\Actions\Cashback;

use App\Models\CashbackReward;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class ListCashbackRewards
{
    private const PAGE_SIZE = 20;

    /** @return LengthAwarePaginator<int, CashbackReward> */
    public function handle(User $user, int $page): LengthAwarePaginator
    {
        return $user->cashbackRewards()
            ->with('userBadge.badge')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(
                perPage: self::PAGE_SIZE,
                page: $page,
            );
    }
}

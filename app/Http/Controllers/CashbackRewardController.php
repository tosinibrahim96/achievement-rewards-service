<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Cashback\ListCashbackRewards;
use App\Http\Requests\Cashback\ListCashbackRewardsRequest;
use App\Http\Resources\CashbackRewardPageResource;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;

final class CashbackRewardController extends Controller
{
    public function __construct(
        private readonly ListCashbackRewards $listCashbackRewards,
    ) {}

    public function index(
        ListCashbackRewardsRequest $request,
        #[CurrentUser] User $user,
    ): CashbackRewardPageResource {
        return new CashbackRewardPageResource(
            $this->listCashbackRewards->handle($user, $request->page()),
        );
    }
}

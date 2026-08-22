<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Payouts\RegisterPayoutAccount;
use App\Http\Requests\Payouts\RegisterPayoutAccountRequest;
use App\Http\Resources\PayoutAccountResource;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class PayoutAccountController extends Controller
{
    public function __construct(
        private readonly RegisterPayoutAccount $registerPayoutAccount,
    ) {}

    public function update(
        RegisterPayoutAccountRequest $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $result = $this->registerPayoutAccount->handle($user, $request->toInput());
        $status = $result->wasCreated ? Response::HTTP_CREATED : Response::HTTP_OK;

        return (new PayoutAccountResource($result->payoutAccount))
            ->response()
            ->setStatusCode($status);
    }
}

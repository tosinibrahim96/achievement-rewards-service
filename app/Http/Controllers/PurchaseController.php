<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Purchases\RecordPurchase;
use App\Http\Requests\Purchases\RecordPurchaseRequest;
use App\Http\Resources\PurchaseIngestionResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class PurchaseController extends Controller
{
    public function __construct(
        private readonly RecordPurchase $recordPurchase,
    ) {}

    public function store(RecordPurchaseRequest $request): JsonResponse
    {
        $result = $this->recordPurchase->handle($request->toInput());
        $status = $result->wasDuplicate ? Response::HTTP_OK : Response::HTTP_CREATED;

        return (new PurchaseIngestionResource($result))
            ->response()
            ->setStatusCode($status);
    }
}

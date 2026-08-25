<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class SelfRenderingTestException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(
            ['message' => 'This shape must not escape.'],
            409,
            ['Retry-After' => '15'],
        );
    }
}

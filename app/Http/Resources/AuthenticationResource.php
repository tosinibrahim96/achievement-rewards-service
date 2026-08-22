<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Data\Auth\AuthenticationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuthenticationResult */
final class AuthenticationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AuthenticationResult $result */
        $result = $this->resource;

        return [
            'user' => new UserResource($result->user),
            'token' => $result->plainTextToken,
            'token_type' => $result->tokenType,
            'abilities' => $result->abilities,
        ];
    }
}

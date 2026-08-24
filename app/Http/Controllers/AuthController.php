<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\LoginCustomer;
use App\Actions\Auth\LoginSystem;
use App\Actions\Auth\RegisterCustomer;
use App\Actions\Auth\RevokeCurrentToken;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\AuthenticationResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class AuthController extends Controller
{
    public function __construct(
        private readonly RegisterCustomer $registerCustomer,
        private readonly LoginCustomer $loginCustomer,
        private readonly LoginSystem $loginSystem,
        private readonly RevokeCurrentToken $revokeCurrentToken,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        return (new AuthenticationResource($this->registerCustomer->handle($request->toInput())))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): AuthenticationResource
    {
        return new AuthenticationResource($this->loginCustomer->handle($request->toInput()));
    }

    public function loginSystem(LoginRequest $request): AuthenticationResource
    {
        return new AuthenticationResource($this->loginSystem->handle($request->toInput()));
    }

    public function logout(#[CurrentUser] User $user): Response
    {
        $this->revokeCurrentToken->handle($user);

        return response()->noContent();
    }

    public function me(#[CurrentUser] User $user): UserResource
    {
        return new UserResource($user);
    }
}

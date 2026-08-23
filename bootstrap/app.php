<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureCustomerAccount;
use App\Http\Middleware\EnsureSystemAccount;
use App\Http\Responses\ApiErrorResponseFactory;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        // There is no browser login route, so return an auth error instead of redirecting guests.
        $middleware->redirectGuestsTo(null);
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'customer-account' => EnsureCustomerAccount::class,
            'system-account' => EnsureSystemAccount::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $shouldRenderJson = static fn (Request $request): bool => $request->is('api/*')
            || $request->routeIs('users.achievements.show')
            || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($shouldRenderJson);

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) use ($shouldRenderJson): Response {
            if (! $shouldRenderJson($request)) {
                return $response;
            }

            return app(ApiErrorResponseFactory::class)->fromException($request, $exception, $response);
        });
    })->create();

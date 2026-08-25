<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Http\Responses\ApiErrorResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\SelfRenderingTestException;

beforeEach(function (): void {
    Route::get('/api/_testing/bad-request', static function (): never {
        abort(400);
    });

    Route::get('/api/_testing/forbidden', static function (): never {
        abort(403);
    });

    Route::get('/api/_testing/conflict', static function (): never {
        Context::add('correlation_id', 'workflow-01KTEST');

        abort(409);
    });

    Route::get('/api/_testing/method', static fn (): array => ['ok' => true]);

    Route::get('/api/_testing/teapot', static function (): never {
        abort(418);
    });

    Route::get('/api/_testing/unexpected', static function (): never {
        throw new RuntimeException('database-password=must-never-be-public');
    });

    Route::get('/api/_testing/unavailable', static function (): never {
        abort(503, headers: ['Retry-After' => '30']);
    });

    Route::get('/api/_testing/bad-gateway', static function (): never {
        abort(502);
    });

    Route::get('/api/_testing/gateway-timeout', static function (): never {
        abort(504);
    });

    Route::get('/api/_testing/self-rendering', static function (): never {
        throw new SelfRenderingTestException;
    });
});

function assertApiErrorResponse(TestResponse $response, int $status, string $code): void
{
    $response
        ->assertStatus($status)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('code', $code)
        ->assertJsonStructure(['code', 'message'])
        ->assertJsonMissingPath('type')
        ->assertJsonMissingPath('title')
        ->assertJsonMissingPath('status')
        ->assertJsonMissingPath('detail')
        ->assertJsonMissingPath('request_id')
        ->assertJsonMissingPath('correlation_id');

    expect($response->headers->get(AssignRequestId::HEADER))
        ->toBeString()
        ->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
}

it('standardizes ordinary client errors', function (string $path, int $status, string $code): void {
    assertApiErrorResponse($this->getJson($path), $status, $code);
})->with([
    'bad request' => ['/api/_testing/bad-request', 400, 'bad_request'],
    'forbidden' => ['/api/_testing/forbidden', 403, 'forbidden'],
    'unknown client status' => ['/api/_testing/teapot', 418, 'http_error'],
]);

it('standardizes not-found responses', function (): void {
    assertApiErrorResponse($this->getJson('/api/_testing/missing'), 404, 'not_found');
});

it('standardizes authentication failures and preserves the bearer challenge', function (): void {
    $response = $this->getJson('/api/me');

    assertApiErrorResponse($response, 401, 'unauthenticated');

    $response->assertHeader('WWW-Authenticate', 'Bearer');
});

it('standardizes method-not-allowed responses and preserves the Allow header', function (): void {
    $response = $this->postJson('/api/_testing/method');

    assertApiErrorResponse($response, 405, 'method_not_allowed');

    expect($response->headers->get('Allow'))->toContain('GET');
});

it('keeps diagnostic identifiers out of the client error body', function (): void {
    assertApiErrorResponse($this->getJson('/api/_testing/conflict'), 409, 'conflict');
});

it('standardizes validation responses with structured field errors', function (): void {
    $response = $this->postJson('/api/auth/login', [
        'email' => 'not-an-email',
        'password' => '',
    ]);

    assertApiErrorResponse($response, 422, 'validation_failed');

    $response->assertJsonStructure([
        'errors' => ['email', 'password'],
    ]);
});

it('keeps unexpected errors generic even when application debugging is enabled', function (): void {
    config()->set('app.debug', true);

    $response = $this->getJson('/api/_testing/unexpected');

    assertApiErrorResponse($response, 500, 'internal_server_error');

    expect($response->getContent())
        ->not->toContain('database-password')
        ->not->toContain(RuntimeException::class)
        ->not->toContain(base_path());
});

it('standardizes service failures and preserves Retry-After', function (): void {
    $response = $this->getJson('/api/_testing/unavailable');

    assertApiErrorResponse($response, 503, 'service_unavailable');

    $response->assertHeader('Retry-After', '30');
});

it('standardizes upstream service failures', function (string $path, int $status, string $code): void {
    assertApiErrorResponse($this->getJson($path), $status, $code);
})->with([
    'bad gateway' => ['/api/_testing/bad-gateway', 502, 'upstream_service_error'],
    'gateway timeout' => ['/api/_testing/gateway-timeout', 504, 'upstream_service_timeout'],
]);

it('prevents self-rendering exceptions from drifting the API error contract', function (): void {
    $response = $this->getJson('/api/_testing/self-rendering');

    assertApiErrorResponse($response, 409, 'conflict');

    $response
        ->assertHeader('Retry-After', '15')
        ->assertJsonPath('message', 'The request conflicts with the current resource state.');
});

it('generates a request identifier when the responder is invoked without the middleware', function (): void {
    $request = Request::create('/api/_testing/direct', server: ['HTTP_ACCEPT' => 'application/json']);
    $response = app(ApiErrorResponseFactory::class)->fromException($request, new RuntimeException('private'));

    expect($response->getStatusCode())->toBe(500)
        ->and($request->attributes->get(AssignRequestId::ATTRIBUTE))->toBeString()
        ->and($response->headers->get(AssignRequestId::HEADER))->toBe(
            $request->attributes->get(AssignRequestId::ATTRIBUTE),
        );
});

it('adds a request identifier header to successful responses', function (): void {
    $response = $this->getJson('/up')->assertOk();

    expect($response->headers->get(AssignRequestId::HEADER))
        ->toBeString()
        ->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

<?php

declare(strict_types=1);

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Yaml\Yaml;

/**
 * @return list<string>
 */
function documentedApiOperations(): array
{
    $document = Yaml::parseFile(base_path('openapi.yaml'));

    if (! is_array($document) || ! isset($document['paths']) || ! is_array($document['paths'])) {
        throw new RuntimeException('The OpenAPI document must contain a paths object.');
    }

    $operations = [];

    foreach ($document['paths'] as $path => $pathItem) {
        if (! is_string($path) || ! is_array($pathItem)) {
            throw new RuntimeException('Every OpenAPI path must contain a path item object.');
        }

        foreach (['delete', 'get', 'head', 'options', 'patch', 'post', 'put', 'trace'] as $method) {
            if (array_key_exists($method, $pathItem)) {
                $operations[] = "{$method} {$path}";
            }
        }
    }

    sort($operations);

    return $operations;
}

/** @return array<string, mixed> */
function openApiDocument(): array
{
    $document = Yaml::parseFile(base_path('openapi.yaml'));

    if (! is_array($document)) {
        throw new RuntimeException('The OpenAPI document must be a YAML object.');
    }

    return $document;
}

/**
 * @return list<string>
 */
function applicationApiOperations(): array
{
    $operations = collect(Route::getRoutes())
        ->filter(static function (LaravelRoute $route): bool {
            $uri = $route->uri();

            return in_array($uri, ['/', 'up'], true)
                || str_starts_with($uri, 'api/')
                || str_starts_with($uri, 'users/');
        })
        ->flatMap(static function (LaravelRoute $route): array {
            $path = $route->uri() === '/' ? '/' : '/'.ltrim($route->uri(), '/');

            return collect($route->methods())
                ->reject(static fn (string $method): bool => $method === 'HEAD')
                ->map(static fn (string $method): string => strtolower($method)." {$path}")
                ->all();
        })
        ->sort()
        ->values()
        ->all();

    return $operations;
}

it('keeps the OpenAPI operations synchronized with the application routes', function (): void {
    expect(documentedApiOperations())
        ->toBe(applicationApiOperations())
        ->toHaveCount(12);
});

it('serves the contract through Scalar in the testing environment', function (): void {
    $this->get('/docs')
        ->assertOk()
        ->assertSee('Scalar.createApiReference', escape: false)
        ->assertSee('@scalar/api-reference@1.66.1', escape: false)
        ->assertSee('/openapi.yaml', escape: false)
        ->assertSee('"persistAuth":false', escape: false)
        ->assertSee('bearerAuth', escape: false);

    $contractResponse = $this->get('/openapi.yaml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/yaml');
    $document = openApiDocument();

    expect($contractResponse->baseResponse)
        ->toBeInstanceOf(BinaryFileResponse::class)
        ->and($contractResponse->baseResponse->getFile()->getRealPath())
        ->toBe(realpath(base_path('openapi.yaml')))
        ->and($document['openapi'] ?? null)
        ->toBe('3.1.0');
});

it('documents authentication and fixed error boundaries as machine-readable rules', function (): void {
    $document = openApiDocument();
    $rootServerError = $document['paths']['/']['get']['responses']['500'];
    $healthServerError = $document['paths']['/up']['get']['responses']['500'];
    $customerLogin = $document['paths']['/api/auth/login']['post'];
    $systemLogin = $document['paths']['/api/auth/system/login']['post'];

    expect($customerLogin['requestBody']['content']['application/json']['schema']['$ref'] ?? null)
        ->toBe('#/components/schemas/LoginRequest')
        ->and($systemLogin['requestBody']['content']['application/json']['schema']['$ref'] ?? null)
        ->toBe('#/components/schemas/LoginRequest')
        ->and($systemLogin['responses']['200']['content']['application/json']['schema']['$ref'] ?? null)
        ->toBe('#/components/schemas/SystemAuthentication')
        ->and(array_keys($systemLogin['responses'] ?? []))
        ->toBe([200, 401, 422, 429, 500])
        ->and($document['components']['schemas']['SystemUser']['properties']['account_type']['enum'] ?? null)
        ->toBe(['system'])
        ->and($document['components']['schemas']['SystemTokenAbility']['enum'] ?? null)
        ->toBe(['purchases:write'])
        ->and($document['components']['schemas']['SystemAuthentication']['properties']['abilities']['minItems'] ?? null)
        ->toBe(1)
        ->and($document['components']['schemas']['SystemAuthentication']['properties']['abilities']['maxItems'] ?? null)
        ->toBe(1)
        ->and($document['paths']['/api/webhooks/paystack']['post']['security'] ?? null)
        ->toBe([['paystackSignature' => []]])
        ->and($document['components']['securitySchemes']['paystackSignature'] ?? null)
        ->toMatchArray([
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'x-paystack-signature',
        ])
        ->and($document['paths']['/users/{user}/achievements']['get']['security'] ?? null)
        ->toBe([
            ['bearerAuth' => []],
            ['laravelSession' => []],
        ])
        ->and($document['components']['schemas']['ValidationError']['required'] ?? null)
        ->toBe(['code', 'message', 'errors'])
        ->and($document['components']['schemas']['ValidationError']['properties']['code']['const'] ?? null)
        ->toBe('validation_failed')
        ->and($document['components']['schemas']['UnauthenticatedError']['allOf'][1]['properties']['code']['const'] ?? null)
        ->toBe('unauthenticated')
        ->and($rootServerError['content']['text/html']['schema']['type'] ?? null)
        ->toBe('string')
        ->and(array_key_exists('headers', $rootServerError))
        ->toBeFalse()
        ->and(array_key_exists('headers', $healthServerError))
        ->toBeFalse()
        ->and($rootServerError['description'] ?? null)
        ->toContain('debug mode enabled')
        ->and($healthServerError['description'] ?? null)
        ->toContain('debug exception');
});

it('serves both documentation surfaces in the local environment', function (): void {
    app()->detectEnvironment(static fn (): string => 'local');

    $this->get('/docs')
        ->assertOk()
        ->assertSee('@scalar/api-reference@1.66.1', escape: false);

    $this->get('/openapi.yaml')->assertOk();
});

it('denies the contract and Scalar outside local and testing', function (): void {
    app()->detectEnvironment(static fn (): string => 'production');

    $this->get('/docs')->assertForbidden();
    $this->get('/openapi.yaml')->assertForbidden();
});

<?php

declare(strict_types=1);

use App\Enums\PaymentProviderFailure;
use App\Exceptions\Payments\PaymentProviderException;
use App\Infrastructure\Payments\PaystackClient;
use App\Infrastructure\Payments\PaystackResponse;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

function paystackClientForHttpTest(
    ?string $secretKey = 'sk_test_inert_contract_key',
    string $baseUrl = 'https://api.paystack.co',
    int $connectTimeoutSeconds = 5,
    int $timeoutSeconds = 15,
): PaystackClient {
    return new PaystackClient(
        http: app(Factory::class),
        secretKey: $secretKey,
        baseUrl: $baseUrl,
        connectTimeoutSeconds: $connectTimeoutSeconds,
        timeoutSeconds: $timeoutSeconds,
    );
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('accepts only test secret keys with a visible ASCII suffix', function (
    string $secretKey,
    bool $expected,
): void {
    expect(paystackClientForHttpTest($secretKey)->hasValidTestSecretKey())->toBe($expected);
})->with([
    'first visible suffix byte' => ['sk_test_!', true],
    'last visible suffix byte' => ['sk_test_~', true],
    'empty suffix' => ['sk_test_', false],
    'space in suffix' => ['sk_test_has space', false],
    'control byte in suffix' => ["sk_test_line\nbreak", false],
    'DEL byte in suffix' => ["sk_test_\x7F", false],
    'non-ASCII byte in suffix' => ["sk_test_\x80", false],
]);

it('matches a signature only for the exact body and configured shared secret', function (): void {
    $body = '{"event":"transfer.success"}';
    $client = paystackClientForHttpTest('sk_test_signature_key');
    $signature = hash_hmac('sha512', $body, 'sk_test_signature_key');
    $signatureFromOtherKey = hash_hmac('sha512', $body, 'sk_test_other_key');

    expect($client->signatureMatchesBody($body, $signature))->toBeTrue()
        ->and($client->signatureMatchesBody($body.' ', $signature))->toBeFalse()
        ->and($client->signatureMatchesBody($body, $signatureFromOtherKey))->toBeFalse();
});

it('rejects signatures outside the exact lowercase SHA-512 text format', function (
    string $signature,
): void {
    expect(paystackClientForHttpTest()->signatureMatchesBody('{}', $signature))->toBeFalse();
})->with([
    'one character short' => str_repeat('a', 127),
    'one character long' => str_repeat('a', 129),
    'uppercase hexadecimal' => str_repeat('A', 128),
    'non-hexadecimal character' => str_repeat('a', 127).'g',
    'space' => str_repeat('a', 127).' ',
]);

it('sends one authenticated bounded JSON request and returns only the parsed envelope', function (): void {
    $options = [];

    Http::fake(function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response([
            'status' => true,
            'message' => 'Balances retrieved',
            'data' => [['currency' => 'NGN', 'balance' => 1700000]],
        ]);
    });

    $response = paystackClientForHttpTest()->get('balance');

    expect($response->httpStatus)->toBe(HttpResponse::HTTP_OK)
        ->and($response->operationSucceeded())->toBeTrue()
        ->and($response->message())->toBe('Balances retrieved')
        ->and($response->data())->toBe([['currency' => 'NGN', 'balance' => 1700000]])
        ->and($response->hasSuccessfulHttpStatus())->toBeTrue()
        ->and($response->hasClientErrorHttpStatus())->toBeFalse()
        ->and($response->hasServerErrorHttpStatus())->toBeFalse()
        ->and($response->latencyMs)->toBeGreaterThanOrEqual(0)
        ->and($options['connect_timeout'] ?? null)->toBe(5)
        ->and($options['timeout'] ?? null)->toBe(15)
        ->and($options['allow_redirects'] ?? null)->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.paystack.co/balance'
        && $request->hasHeader('Authorization', 'Bearer sk_test_inert_contract_key')
        && $request->hasHeader('Accept', 'application/json'));
    Http::assertSentCount(1);
});

it('classifies HTTP status families without treating one exact status as the whole family', function (
    int $httpStatus,
    bool $successful,
    bool $clientError,
    bool $serverError,
): void {
    $response = new PaystackResponse($httpStatus, [], 0);

    expect($response->hasSuccessfulHttpStatus())->toBe($successful)
        ->and($response->hasClientErrorHttpStatus())->toBe($clientError)
        ->and($response->hasServerErrorHttpStatus())->toBe($serverError);
})->with([
    'protocol lower bound' => [HttpResponse::HTTP_CONTINUE, false, false, false],
    'informational upper bound' => [199, false, false, false],
    'success lower bound' => [HttpResponse::HTTP_OK, true, false, false],
    'success upper bound' => [299, true, false, false],
    'redirect lower bound' => [HttpResponse::HTTP_MULTIPLE_CHOICES, false, false, false],
    'redirect upper bound' => [399, false, false, false],
    'client-error lower bound' => [HttpResponse::HTTP_BAD_REQUEST, false, true, false],
    'client-error upper bound' => [499, false, true, false],
    'server-error lower bound' => [HttpResponse::HTTP_INTERNAL_SERVER_ERROR, false, false, true],
    'protocol upper bound' => [599, false, false, true],
]);

it('rejects statuses outside the HTTP protocol range', function (int $httpStatus): void {
    expect(fn () => new PaystackResponse($httpStatus, [], 0))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'below range' => 99,
    'above range' => 600,
]);

it('posts exact JSON without adding transport retries', function (): void {
    Http::fake(['*' => Http::response(['status' => true, 'data' => ['ok' => true]])]);

    paystackClientForHttpTest()->post('transfer', [
        'source' => 'balance',
        'amount' => 30_000,
    ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.paystack.co/transfer'
        && $request->data() === ['source' => 'balance', 'amount' => 30_000]
        && $request->hasHeader('Content-Type', 'application/json'));
    Http::assertSentCount(1);
});

it('fails before I/O for unsafe optional-provider configuration', function (
    ?string $secretKey,
    string $baseUrl,
    int $connectTimeout,
    int $timeout,
): void {
    Http::fake();

    try {
        paystackClientForHttpTest($secretKey, $baseUrl, $connectTimeout, $timeout)->get('balance');
        test()->fail('Invalid Paystack configuration should fail before a request.');
    } catch (PaymentProviderException $exception) {
        expect($exception->failure)->toBe(PaymentProviderFailure::Unavailable)
            ->and($exception->getMessage())->toBe(PaymentProviderFailure::Unavailable->value);

        if ($secretKey !== null && $secretKey !== '') {
            expect($exception->getMessage())->not->toContain($secretKey);
        }
    }

    Http::assertNothingSent();
})->with([
    'missing secret' => [null, 'https://api.paystack.co', 5, 15],
    'empty secret' => ['', 'https://api.paystack.co', 5, 15],
    'public key' => ['pk_test_not_a_secret', 'https://api.paystack.co', 5, 15],
    'live key' => ['sk_live_not_permitted_in_this_service', 'https://api.paystack.co', 5, 15],
    'test prefix without secret material' => ['sk_test_', 'https://api.paystack.co', 5, 15],
    'whitespace-padded secret' => [' sk_test_inert ', 'https://api.paystack.co', 5, 15],
    'non-HTTPS URL' => ['sk_test_inert', 'http://api.paystack.co', 5, 15],
    'credential-bearing URL' => ['sk_test_inert', 'https://user:pass@api.paystack.co', 5, 15],
    'path-bearing URL' => ['sk_test_inert', 'https://api.paystack.co/v1', 5, 15],
    'query-bearing URL' => ['sk_test_inert', 'https://api.paystack.co?tenant=unexpected', 5, 15],
    'fragment-bearing URL' => ['sk_test_inert', 'https://api.paystack.co#unexpected', 5, 15],
    'zero connection timeout' => ['sk_test_inert', 'https://api.paystack.co', 0, 15],
    'total below connection timeout' => ['sk_test_inert', 'https://api.paystack.co', 5, 4],
]);

it('rejects control characters before building a credential-bearing request', function (): void {
    $secretKey = "sk_test_inert\nembedded_secret";
    Http::fake();

    try {
        paystackClientForHttpTest($secretKey)->get('balance');
        test()->fail('A control character must fail before the Authorization header is built.');
    } catch (PaymentProviderException $exception) {
        $applicationFrames = array_filter(
            $exception->getTrace(),
            static fn (array $frame): bool => str_starts_with((string) ($frame['class'] ?? ''), 'App\\'),
        );
        $renderedTrace = json_encode($applicationFrames, JSON_THROW_ON_ERROR);

        expect($exception->failure)->toBe(PaymentProviderFailure::Unavailable)
            ->and($exception->getMessage())->toBe(PaymentProviderFailure::Unavailable->value)
            ->and($renderedTrace)->not->toContain('sk_test_inert')
            ->and($renderedTrace)->not->toContain('embedded_secret');
    }

    Http::assertNothingSent();
});

it('classifies a transport timeout once without retaining its sensitive URL', function (): void {
    $accountNumber = '0000000000';
    Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);

    try {
        paystackClientForHttpTest()->get('bank/resolve', [
            'account_number' => $accountNumber,
            'bank_code' => '057',
        ]);
        test()->fail('The timeout should be sanitized.');
    } catch (PaymentProviderException $exception) {
        expect($exception->failure)->toBe(PaymentProviderFailure::Timeout)
            ->and($exception->getMessage())->not->toContain($accountNumber)
            ->and($exception->getPrevious())->toBeNull();
    }

    Http::assertSentCount(1);
});

it('classifies a non-timeout connection failure as unavailable without leaking transport detail', function (): void {
    Http::fake(['*' => Http::failedConnection('Could not resolve a sensitive host')]);

    try {
        paystackClientForHttpTest()->get('balance');
        test()->fail('The unavailable connection should be sanitized.');
    } catch (PaymentProviderException $exception) {
        expect($exception->failure)->toBe(PaymentProviderFailure::Unavailable)
            ->and($exception->getMessage())->not->toContain('sensitive host')
            ->and($exception->getPrevious())->toBeNull();
    }

    Http::assertSentCount(1);
});

it('rejects malformed success payloads without copying the body or secret', function (mixed $body): void {
    Http::fake(['*' => Http::response($body, HttpResponse::HTTP_OK)]);

    try {
        paystackClientForHttpTest()->get('balance');
        test()->fail('Malformed JSON should be rejected.');
    } catch (PaymentProviderException $exception) {
        expect($exception->failure)->toBe(PaymentProviderFailure::MalformedResponse)
            ->and($exception->getMessage())->not->toContain('provider-secret')
            ->and($exception->getMessage())->not->toContain('sk_test_inert_contract_key');
    }
})->with([
    'non-JSON body' => ['provider-secret'],
    'scalar JSON' => ['"provider-secret"'],
    'list JSON' => [[['provider-secret']]],
]);

it('accepts the documented legacy string false only as an error status', function (): void {
    Http::fake(['*' => Http::response([
        'status' => 'false',
        'message' => 'Rate limit exceeded!',
        'code' => 'rate_limited',
    ], HttpResponse::HTTP_TOO_MANY_REQUESTS)]);

    $response = paystackClientForHttpTest()->get('balance');

    expect($response->operationSucceeded())->toBeFalse()
        ->and($response->providerCode())->toBe('rate_limited');
});

it('does not widen a string true into provider success', function (): void {
    Http::fake(['*' => Http::response([
        'status' => 'true',
        'data' => ['balance' => 30_000],
    ])]);

    $response = paystackClientForHttpTest()->get('balance');

    expect($response->operationSucceeded())->toBeNull();
});

it('classifies HTTP timeout statuses regardless of whether the response is JSON', function (
    int $httpStatus,
    string|array $body,
): void {
    Http::fake(['*' => Http::response($body, $httpStatus)]);

    try {
        paystackClientForHttpTest()->post('transfer', [
            'reference' => 'cashback-01arz3ndektsv4rrffq69g5fav',
        ]);
        test()->fail('An HTTP timeout status must remain a timeout.');
    } catch (PaymentProviderException $exception) {
        expect($exception->failure)->toBe(PaymentProviderFailure::Timeout);
    }

    Http::assertSentCount(1);
})->with([
    'JSON request timeout' => [HttpResponse::HTTP_REQUEST_TIMEOUT, ['status' => false, 'message' => 'Request timed out']],
    'JSON gateway timeout' => [HttpResponse::HTTP_GATEWAY_TIMEOUT, ['status' => false, 'message' => 'Gateway timed out']],
    'non-JSON gateway timeout' => [HttpResponse::HTTP_GATEWAY_TIMEOUT, 'Gateway timed out'],
]);

it('redacts sensitive query and raw response arguments from application exception frames', function (): void {
    $accountNumber = '0000000000';
    Http::fake(['*' => Http::response([[
        'account_number' => $accountNumber,
    ]])]);

    try {
        paystackClientForHttpTest()->get('bank/resolve', [
            'account_number' => $accountNumber,
            'bank_code' => '057',
        ]);
        test()->fail('The list response should be rejected.');
    } catch (PaymentProviderException $exception) {
        $applicationFrames = array_filter(
            $exception->getTrace(),
            static fn (array $frame): bool => str_starts_with((string) ($frame['class'] ?? ''), 'App\\'),
        );
        $renderedTrace = json_encode($applicationFrames, JSON_THROW_ON_ERROR);

        expect($renderedTrace)->not->toContain($accountNumber)
            ->and($renderedTrace)->not->toContain('sk_test_inert_contract_key');
    }
});

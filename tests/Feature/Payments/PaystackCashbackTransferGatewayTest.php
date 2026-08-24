<?php

declare(strict_types=1);

use App\Data\Payments\CashbackTransferRequest;
use App\Enums\CashbackTransferErrorCode;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PaymentProviderFailure;
use App\Enums\PayoutStatus;
use App\Exceptions\Payments\PaymentProviderException;
use App\Infrastructure\Payments\PaystackCashbackTransferGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

function paystackTransferRequestForTest(): CashbackTransferRequest
{
    return new CashbackTransferRequest(
        providerReference: 'cashback-01arz3ndektsv4rrffq69g5fav',
        recipientCode: 'RCP_paystack_contract',
        amountMinor: 30_000,
        currency: Currency::Ngn,
    );
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function paystackTransferDataForTest(string $status, array $overrides = []): array
{
    return [
        'reference' => 'cashback-01arz3ndektsv4rrffq69g5fav',
        'amount' => 30_000,
        'currency' => 'NGN',
        'source' => 'balance',
        'status' => $status,
        'transfer_code' => 'TRF_paystack_contract',
        ...$overrides,
    ];
}

beforeEach(function (): void {
    config()->set('payments.paystack.secret_key', 'sk_test_inert_transfer_key');
    config()->set('payments.paystack.base_url', 'https://api.paystack.co');
    config()->set('payments.paystack.connect_timeout_seconds', 5);
    config()->set('payments.paystack.timeout_seconds', 15);
    Http::preventStrayRequests();
});

it('sends the exact stable-reference balance-source transfer and maps provider lifecycle facts', function (
    string $rawStatus,
    PayoutStatus $expectedStatus,
    ?CashbackTransferErrorCode $expectedErrorCode,
): void {
    Http::fake(['*' => Http::response([
        'status' => true,
        'message' => 'Transfer has been queued',
        'data' => paystackTransferDataForTest($rawStatus),
    ])]);

    $gateway = app(PaystackCashbackTransferGateway::class);
    $request = paystackTransferRequestForTest();
    $result = $gateway->initiateTransfer($request);

    expect($gateway->provider())->toBe(PaymentProvider::Paystack)
        ->and($result->status)->toBe($expectedStatus)
        ->and($result->transferCode)->toBe('TRF_paystack_contract')
        ->and($result->httpStatus)->toBe(HttpResponse::HTTP_OK)
        ->and($result->errorCode)->toBe($expectedErrorCode)
        ->and($result->latencyMs)->toBeGreaterThanOrEqual(0);

    Http::assertSent(fn (Request $sent): bool => $sent->method() === 'POST'
        && $sent->url() === 'https://api.paystack.co/transfer'
        && $sent->data() === [
            'source' => 'balance',
            'amount' => 30_000,
            'recipient' => 'RCP_paystack_contract',
            'reference' => 'cashback-01arz3ndektsv4rrffq69g5fav',
            'currency' => 'NGN',
        ]
        && $sent->hasHeader('Authorization', 'Bearer sk_test_inert_transfer_key'));
    Http::assertSentCount(1);
})->with([
    'test success' => ['success', PayoutStatus::Succeeded, null],
    'live-like pending' => ['pending', PayoutStatus::Pending, null],
    'defensive received' => ['received', PayoutStatus::Pending, null],
    'unexpected OTP' => ['otp', PayoutStatus::OtpRequired, CashbackTransferErrorCode::OtpRequired],
    'provider failed' => ['failed', PayoutStatus::Failed, CashbackTransferErrorCode::TransferFailed],
    'provider abandoned' => ['abandoned', PayoutStatus::Failed, CashbackTransferErrorCode::TransferFailed],
    'provider blocked' => ['blocked', PayoutStatus::Failed, CashbackTransferErrorCode::TransferFailed],
    'rejected transfer with a code' => ['rejected', PayoutStatus::Failed, CashbackTransferErrorCode::TransferFailed],
    'provider reversal' => ['reversed', PayoutStatus::Reversed, CashbackTransferErrorCode::TransferReversed],
]);

it('stops on unexpected OTP and calls no finalization resend or setting endpoint', function (): void {
    Http::fake(['*' => Http::response([
        'status' => true,
        'message' => 'Transfer requires OTP to continue',
        'data' => paystackTransferDataForTest('otp'),
    ])]);

    $result = app(PaystackCashbackTransferGateway::class)
        ->initiateTransfer(paystackTransferRequestForTest());

    expect($result->status)->toBe(PayoutStatus::OtpRequired)
        ->and($result->transferCode)->toBe('TRF_paystack_contract');
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => in_array(
        parse_url($request->url(), PHP_URL_PATH),
        [
            '/transfer/finalize_transfer',
            '/transfer/resend_otp',
            '/transfer/disable_otp',
            '/transfer/disable_otp_finalize',
            '/transfer/enable_otp',
        ],
        true,
    ));
});

it('treats malformed or contradictory created responses as ambiguous', function (
    array $data,
    CashbackTransferErrorCode $errorCode,
): void {
    Http::fake(['*' => Http::response(['status' => true, 'data' => $data])]);

    $result = app(PaystackCashbackTransferGateway::class)
        ->initiateTransfer(paystackTransferRequestForTest());

    expect($result->status)->toBe(PayoutStatus::Ambiguous)
        ->and($result->errorCode)->toBe($errorCode)
        ->and($result->errorMessage)->toBe('Paystack did not return a conclusive transfer result.');
    Http::assertSentCount(1);
})->with([
    'list-shaped transfer data' => [[], CashbackTransferErrorCode::ProviderInvalidResponse],
    'reference mismatch' => [paystackTransferDataForTest('success', ['reference' => 'cashback-01wrongreferencevalue']), CashbackTransferErrorCode::ProviderInvalidResponse],
    'amount mismatch' => [paystackTransferDataForTest('success', ['amount' => 30_001]), CashbackTransferErrorCode::ProviderInvalidResponse],
    'currency mismatch' => [paystackTransferDataForTest('success', ['currency' => 'GHS']), CashbackTransferErrorCode::ProviderInvalidResponse],
    'source mismatch' => [paystackTransferDataForTest('success', ['source' => 'card']), CashbackTransferErrorCode::ProviderInvalidResponse],
    'missing transfer code' => [paystackTransferDataForTest('success', ['transfer_code' => null]), CashbackTransferErrorCode::ProviderTransferIdentityMissing],
    'unknown lifecycle status' => [paystackTransferDataForTest('new_provider_state'), CashbackTransferErrorCode::ProviderStatusUnknown],
]);

it('never treats a contradictory non-success HTTP envelope as proof of a created transfer', function (): void {
    Http::fake(['*' => Http::response([
        'status' => true,
        'message' => 'Contradictory upstream response',
        'data' => paystackTransferDataForTest('success'),
    ], HttpResponse::HTTP_INTERNAL_SERVER_ERROR)]);

    $result = app(PaystackCashbackTransferGateway::class)
        ->initiateTransfer(paystackTransferRequestForTest());

    expect($result->status)->toBe(PayoutStatus::Ambiguous)
        ->and($result->transferCode)->toBe('TRF_paystack_contract')
        ->and($result->httpStatus)->toBe(HttpResponse::HTTP_INTERNAL_SERVER_ERROR)
        ->and($result->errorCode)->toBe(CashbackTransferErrorCode::ProviderInvalidResponse);
    Http::assertSentCount(1);
});

it('maps rejected requests with no transfer data without persisting raw provider messages', function (
    int $httpStatus,
    bool|string $outerStatus,
    string $message,
    ?string $providerCode,
    PayoutStatus $payoutStatus,
    CashbackTransferErrorCode $errorCode,
): void {
    $body = [
        'status' => $outerStatus,
        'message' => $message,
    ];

    if ($providerCode !== null) {
        $body['code'] = $providerCode;
    }

    Http::fake(['*' => Http::response($body, $httpStatus)]);

    $result = app(PaystackCashbackTransferGateway::class)
        ->initiateTransfer(paystackTransferRequestForTest());

    expect($result->status)->toBe($payoutStatus)
        ->and($result->transferCode)->toBeNull()
        ->and($result->httpStatus)->toBe($httpStatus)
        ->and($result->errorCode)->toBe($errorCode)
        ->and($result->errorMessage)->not->toContain($message)
        ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('0000000000');
    Http::assertSentCount(1);
})->with([
    'insufficient balance US spelling' => [HttpResponse::HTTP_BAD_REQUEST, false, 'Your balance is not enough to fulfill this request', null, PayoutStatus::InsufficientFunds, CashbackTransferErrorCode::InsufficientFunds],
    'insufficient balance UK spelling' => [HttpResponse::HTTP_BAD_REQUEST, 'false', 'Your balance is not enough to fulfil this request', null, PayoutStatus::InsufficientFunds, CashbackTransferErrorCode::InsufficientFunds],
    'insufficient balance normalized whitespace case and punctuation' => [HttpResponse::HTTP_BAD_REQUEST, false, "  YOUR\tBALANCE  IS NOT ENOUGH TO FULFILL THIS REQUEST!!  ", null, PayoutStatus::InsufficientFunds, CashbackTransferErrorCode::InsufficientFunds],
    'near-miss balance message remains rejected' => [HttpResponse::HTTP_BAD_REQUEST, false, 'Your balance is not enough to fulfil this request right now', null, PayoutStatus::PermanentRejection, CashbackTransferErrorCode::ProviderRejected],
    'rate limited' => [HttpResponse::HTTP_TOO_MANY_REQUESTS, false, 'Rate limit exceeded!', 'rate_limited', PayoutStatus::RetryableRejection, CashbackTransferErrorCode::RateLimited],
    'rate-limited provider code' => [HttpResponse::HTTP_BAD_REQUEST, false, 'Request rejected', 'rate_limited', PayoutStatus::RetryableRejection, CashbackTransferErrorCode::RateLimited],
    'recipient validation' => [HttpResponse::HTTP_BAD_REQUEST, false, 'Recipient 0000000000 specified is invalid', 'missing_params', PayoutStatus::PermanentRejection, CashbackTransferErrorCode::ProviderRejected],
    'invalid credential' => [HttpResponse::HTTP_UNAUTHORIZED, false, 'Invalid key', null, PayoutStatus::PermanentRejection, CashbackTransferErrorCode::ProviderUnavailable],
    'forbidden credential' => [HttpResponse::HTTP_FORBIDDEN, false, 'Transfers are unavailable', null, PayoutStatus::PermanentRejection, CashbackTransferErrorCode::ProviderUnavailable],
    'server ambiguity' => [HttpResponse::HTTP_INTERNAL_SERVER_ERROR, false, 'System Malfunction 0000000000', null, PayoutStatus::Ambiguous, CashbackTransferErrorCode::ProviderUnavailable],
    'insufficient phrase on server error' => [HttpResponse::HTTP_INTERNAL_SERVER_ERROR, false, 'Your balance is not enough to fulfill this request', null, PayoutStatus::Ambiguous, CashbackTransferErrorCode::ProviderUnavailable],
    'duplicate reference ambiguity' => [HttpResponse::HTTP_BAD_REQUEST, false, 'A transfer with this reference already exists', null, PayoutStatus::Ambiguous, CashbackTransferErrorCode::DuplicateReference],
]);

it('treats a rejected response containing transfer data as ambiguous', function (): void {
    Http::fake(['*' => Http::response([
        'status' => false,
        'message' => 'Rate limit exceeded after an uncertain provider transition',
        'code' => 'rate_limited',
        'data' => paystackTransferDataForTest('pending'),
    ], HttpResponse::HTTP_TOO_MANY_REQUESTS)]);

    $result = app(PaystackCashbackTransferGateway::class)
        ->initiateTransfer(paystackTransferRequestForTest());

    expect($result->status)->toBe(PayoutStatus::Ambiguous)
        ->and($result->transferCode)->toBe('TRF_paystack_contract')
        ->and($result->errorCode)->toBe(CashbackTransferErrorCode::ProviderInvalidResponse);
    Http::assertSentCount(1);
});

it('treats rejected transfer data without a transfer code as ambiguous', function (): void {
    Http::fake(['*' => Http::response([
        'status' => false,
        'message' => 'Your balance is not enough to fulfill this request',
        'data' => paystackTransferDataForTest('pending', ['transfer_code' => null]),
    ], HttpResponse::HTTP_BAD_REQUEST)]);

    $result = app(PaystackCashbackTransferGateway::class)
        ->initiateTransfer(paystackTransferRequestForTest());

    expect($result->status)->toBe(PayoutStatus::Ambiguous)
        ->and($result->transferCode)->toBeNull()
        ->and($result->errorCode)->toBe(CashbackTransferErrorCode::ProviderInvalidResponse);
    Http::assertSentCount(1);
});

it('normalizes malformed or timed-out POST results to one ambiguous observation without retrying', function (
    string $scenario,
    CashbackTransferErrorCode $expectedCode,
): void {
    if ($scenario === 'timeout') {
        Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);
    } elseif ($scenario === 'unavailable') {
        Http::fake(['*' => Http::failedConnection('Could not resolve provider host')]);
    } elseif ($scenario === 'opaque-server-error') {
        Http::fake(['*' => Http::response('<html>Bad gateway</html>', HttpResponse::HTTP_BAD_GATEWAY)]);
    } else {
        Http::fake(['*' => Http::response('{not-json', HttpResponse::HTTP_OK)]);
    }

    $result = app(PaystackCashbackTransferGateway::class)
        ->initiateTransfer(paystackTransferRequestForTest());

    expect($result->status)->toBe(PayoutStatus::Ambiguous)
        ->and($result->errorCode)->toBe($expectedCode)
        ->and($result->transferCode)->toBeNull();
    Http::assertSentCount(1);
})->with([
    'timeout' => ['timeout', CashbackTransferErrorCode::ProviderTimeout],
    'unavailable connection' => ['unavailable', CashbackTransferErrorCode::ProviderUnavailable],
    'opaque server failure' => ['opaque-server-error', CashbackTransferErrorCode::ProviderUnavailable],
    'malformed JSON' => ['malformed', CashbackTransferErrorCode::ProviderInvalidResponse],
]);

it('rejects an invalid stored reference before sending provider work', function (): void {
    Http::fake();
    $invalid = new CashbackTransferRequest(
        providerReference: 'UPPERCASE-INVALID',
        recipientCode: 'RCP_paystack_contract',
        amountMinor: 30_000,
        currency: Currency::Ngn,
    );

    $result = app(PaystackCashbackTransferGateway::class)->initiateTransfer($invalid);

    expect($result->status)->toBe(PayoutStatus::PermanentRejection)
        ->and($result->errorCode)->toBe(CashbackTransferErrorCode::InvalidProviderReference);
    Http::assertNothingSent();
});

it('rejects missing local test configuration conclusively before provider I/O', function (): void {
    config()->set('payments.paystack.secret_key');
    Http::fake();

    $result = app(PaystackCashbackTransferGateway::class)
        ->initiateTransfer(paystackTransferRequestForTest());

    expect($result->status)->toBe(PayoutStatus::PermanentRejection)
        ->and($result->transferCode)->toBeNull()
        ->and($result->errorCode)->toBe(CashbackTransferErrorCode::ProviderUnavailable)
        ->and($result->errorMessage)->toBe('Paystack is not configured for test transfers.');
    Http::assertNothingSent();
});

it('selects the one NGN balance by currency rather than response position', function (): void {
    Http::fake(['*' => Http::response([
        'status' => true,
        'message' => 'Balances retrieved',
        'data' => [
            ['currency' => 'GHS', 'balance' => 5_000],
            ['currency' => 'NGN', 'balance' => 1_700_000],
        ],
    ])]);

    $balance = app(PaystackCashbackTransferGateway::class)->availableBalance(Currency::Ngn);

    expect($balance->currency)->toBe(Currency::Ngn)
        ->and($balance->amountMinor)->toBe(1_700_000);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.paystack.co/balance');
    Http::assertSentCount(1);
});

it('accepts an exact zero NGN balance as a valid provider observation', function (): void {
    Http::fake(['*' => Http::response([
        'status' => true,
        'data' => [['currency' => 'NGN', 'balance' => 0]],
    ])]);

    $balance = app(PaystackCashbackTransferGateway::class)->availableBalance(Currency::Ngn);

    expect($balance->amountMinor)->toBe(0)
        ->and($balance->currency)->toBe(Currency::Ngn);
});

it('fails closed for every malformed NGN balance representation', function (mixed $data): void {
    Http::fake(['*' => Http::response(['status' => true, 'data' => $data])]);

    try {
        app(PaystackCashbackTransferGateway::class)->availableBalance(Currency::Ngn);
        test()->fail('Malformed balance data should not become a zero balance.');
    } catch (PaymentProviderException $exception) {
        expect($exception->failure)->toBe(PaymentProviderFailure::MalformedResponse);
    }
})->with([
    'missing NGN' => [[['currency' => 'GHS', 'balance' => 100]]],
    'duplicate NGN' => [[['currency' => 'NGN', 'balance' => 100], ['currency' => 'NGN', 'balance' => 200]]],
    'negative' => [[['currency' => 'NGN', 'balance' => -1]]],
    'numeric string' => [[['currency' => 'NGN', 'balance' => '100']]],
    'float' => [[['currency' => 'NGN', 'balance' => 100.5]]],
    'object instead of list' => [['currency' => 'NGN', 'balance' => 100]],
]);

it('keeps balance transport and provider failures typed without exposing provider messages', function (
    string $scenario,
    PaymentProviderFailure $failure,
): void {
    if ($scenario === 'connection-timeout') {
        Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);
    } elseif ($scenario === 'http-timeout') {
        Http::fake(['*' => Http::response([
            'status' => false,
            'message' => 'Sensitive balance timeout detail',
        ], HttpResponse::HTTP_GATEWAY_TIMEOUT)]);
    } elseif ($scenario === 'malformed') {
        Http::fake(['*' => Http::response('{not-json', HttpResponse::HTTP_OK)]);
    } elseif ($scenario === 'invalid-status') {
        Http::fake(['*' => Http::response([
            'message' => 'Balances retrieved without a valid status',
            'data' => [['currency' => 'NGN', 'balance' => 100]],
        ], HttpResponse::HTTP_OK)]);
    } else {
        $status = match ($scenario) {
            'unauthorized' => HttpResponse::HTTP_UNAUTHORIZED,
            'rate-limited' => HttpResponse::HTTP_TOO_MANY_REQUESTS,
            default => HttpResponse::HTTP_INTERNAL_SERVER_ERROR,
        };
        Http::fake(['*' => Http::response([
            'status' => false,
            'message' => 'Sensitive provider balance detail',
        ], $status)]);
    }

    try {
        app(PaystackCashbackTransferGateway::class)->availableBalance(Currency::Ngn);
        test()->fail('A failed balance read must remain typed.');
    } catch (PaymentProviderException $exception) {
        expect($exception->failure)->toBe($failure)
            ->and($exception->getMessage())->not->toContain('Sensitive');
    }

    Http::assertSentCount(1);
})->with([
    'connection timeout' => ['connection-timeout', PaymentProviderFailure::Timeout],
    'HTTP timeout' => ['http-timeout', PaymentProviderFailure::Timeout],
    'malformed JSON' => ['malformed', PaymentProviderFailure::MalformedResponse],
    'missing envelope status' => ['invalid-status', PaymentProviderFailure::MalformedResponse],
    'unauthorized' => ['unauthorized', PaymentProviderFailure::Unavailable],
    'rate limited' => ['rate-limited', PaymentProviderFailure::Unavailable],
    'server failure' => ['server', PaymentProviderFailure::Unavailable],
]);

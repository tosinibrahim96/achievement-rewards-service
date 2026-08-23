<?php

declare(strict_types=1);

use App\Actions\Payouts\RegisterPayoutAccount;
use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PaymentProviderFailure;
use App\Events\PayoutAccountVerified;
use App\Exceptions\Payments\PaymentProviderException;
use App\Infrastructure\Payments\PaymentProviderRegistry;
use App\Infrastructure\Payments\PaystackTransferRecipientGateway;
use App\Models\PayoutAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

uses(DatabaseMigrations::class);

/** @return array<string, mixed> */
function successfulPaystackResolution(string $accountNumber = '0000000000'): array
{
    return [
        'status' => true,
        'message' => 'Account number resolved',
        'data' => [
            'account_number' => $accountNumber,
            'account_name' => 'TEST CUSTOMER',
        ],
    ];
}

/** @return array<string, mixed> */
function successfulPaystackRecipient(string $accountNumber = '0000000000'): array
{
    return [
        'status' => true,
        'message' => 'Transfer recipient created successfully',
        'data' => [
            'active' => true,
            'currency' => 'NGN',
            'name' => 'TEST CUSTOMER',
            'recipient_code' => 'RCP_paystack_contract',
            'type' => 'nuban',
            'details' => [
                'account_number' => $accountNumber,
                'account_name' => null,
                'bank_code' => '057',
                'bank_name' => 'Zenith Bank',
            ],
        ],
    ];
}

beforeEach(function (): void {
    config()->set('payments.paystack.secret_key', 'sk_test_inert_recipient_key');
    config()->set('payments.paystack.base_url', 'https://api.paystack.co');
    config()->set('payments.paystack.connect_timeout_seconds', 5);
    config()->set('payments.paystack.timeout_seconds', 15);
    Http::preventStrayRequests();
});

it('keeps the payout-account lock lease above both sequential Paystack request timeouts', function (): void {
    $lockLeaseSeconds = config('payments.payout_account_lock.seconds');
    $requestTimeoutSeconds = config('payments.paystack.timeout_seconds');

    expect($lockLeaseSeconds)->toBeInt()
        ->and($requestTimeoutSeconds)->toBeInt();

    if (! is_int($lockLeaseSeconds) || ! is_int($requestTimeoutSeconds)) {
        test()->fail('Payment timing configuration must use integer seconds.');
    }

    expect($lockLeaseSeconds)->toBeGreaterThan($requestTimeoutSeconds * 2);
});

it('resolves a leading-zero account then creates one canonical masked recipient', function (): void {
    $accountNumber = '0000000000';
    Http::fakeSequence()
        ->push(successfulPaystackResolution($accountNumber))
        ->push(successfulPaystackRecipient($accountNumber));

    $recipient = app(PaystackTransferRecipientGateway::class)->createRecipient(
        new RegisterPayoutAccountInput($accountNumber, '057'),
    );

    expect($recipient->provider)->toBe(PaymentProvider::Paystack)
        ->and($recipient->recipientCode)->toBe('RCP_paystack_contract')
        ->and($recipient->accountName)->toBe('TEST CUSTOMER')
        ->and($recipient->bankName)->toBe('Zenith Bank')
        ->and($recipient->bankCode)->toBe('057')
        ->and($recipient->accountLastFour)->toBe('0000')
        ->and($recipient->currency)->toBe(Currency::Ngn)
        ->and(json_encode($recipient, JSON_THROW_ON_ERROR))->not->toContain($accountNumber);

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.paystack.co/bank/resolve?account_number=0000000000&bank_code=057'
            && $request->hasHeader('Authorization', 'Bearer sk_test_inert_recipient_key'),
        fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.paystack.co/transferrecipient'
            && $request->data() === [
                'type' => 'nuban',
                'name' => 'TEST CUSTOMER',
                'account_number' => $accountNumber,
                'bank_code' => '057',
                'currency' => 'NGN',
            ],
    ]);
});

it('persists only the safe Paystack recipient through the provider-neutral Action', function (): void {
    Event::fake([PayoutAccountVerified::class]);
    config()->set('payments.default', PaymentProvider::Paystack->value);
    Http::fakeSequence()
        ->push(successfulPaystackResolution())
        ->push(successfulPaystackRecipient());
    $user = User::factory()->create();

    $result = app(RegisterPayoutAccount::class)->handle(
        $user,
        new RegisterPayoutAccountInput('0000000000', '057'),
    );
    $account = $result->payoutAccount;

    expect($result->wasCreated)->toBeTrue()
        ->and($account->provider)->toBe(PaymentProvider::Paystack)
        ->and($account->provider_recipient_code)->toBe('RCP_paystack_contract')
        ->and($account->account_name)->toBe('TEST CUSTOMER')
        ->and($account->bank_name)->toBe('Zenith Bank')
        ->and($account->account_last_four)->toBe('0000')
        ->and(json_encode($account->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain('0000000000')
        ->and(PayoutAccount::query()->whereBelongsTo($user)->count())->toBe(1);
    Event::assertDispatched(PayoutAccountVerified::class);
});

it('keeps the credential-free fake default resolvable while both Paystack adapters are registered', function (): void {
    config()->set('payments.default', PaymentProvider::Fake->value);
    config()->set('payments.paystack.secret_key');

    $registry = app(PaymentProviderRegistry::class);

    expect($registry->defaultRecipientGateway()->provider())->toBe(PaymentProvider::Fake)
        ->and($registry->recipientGatewayFor(PaymentProvider::Paystack)->provider())->toBe(PaymentProvider::Paystack)
        ->and($registry->transferGatewayFor(PaymentProvider::Paystack)->provider())->toBe(PaymentProvider::Paystack);
    Http::assertNothingSent();
});

it('maps a documented account-resolution rejection without issuing recipient creation', function (): void {
    $accountNumber = '0000000000';
    Http::fake(['*' => Http::response([
        'status' => 'false',
        'message' => 'Could not resolve account name. Check parameters or try again.',
    ], HttpResponse::HTTP_UNPROCESSABLE_ENTITY)]);

    try {
        app(PaystackTransferRecipientGateway::class)->createRecipient(
            new RegisterPayoutAccountInput($accountNumber, '057'),
        );
        test()->fail('The rejected account should not create a recipient.');
    } catch (PaymentProviderException $exception) {
        expect($exception->failure)->toBe(PaymentProviderFailure::RecipientRejected)
            ->and($exception->getMessage())->not->toContain($accountNumber);
    }

    Http::assertSentCount(1);
});

it('maps a recipient-creation rejection after trusting only the resolved name', function (): void {
    Http::fakeSequence()
        ->push(successfulPaystackResolution())
        ->push([
            'status' => false,
            'message' => 'Bank is invalid for account 0000000000',
        ], HttpResponse::HTTP_BAD_REQUEST);

    try {
        app(PaystackTransferRecipientGateway::class)->createRecipient(
            new RegisterPayoutAccountInput('0000000000', '057'),
        );
        test()->fail('The rejected recipient should be sanitized.');
    } catch (PaymentProviderException $exception) {
        expect($exception->failure)->toBe(PaymentProviderFailure::RecipientRejected)
            ->and($exception->getMessage())->not->toContain('0000000000');
    }

    Http::assertSentCount(2);
});

it('classifies malformed resolved or recipient identity fields and retains no provider payload', function (
    array $resolution,
    ?array $recipient,
): void {
    $sequence = Http::fakeSequence()->push($resolution);

    if ($recipient !== null) {
        $sequence->push($recipient);
    }

    try {
        app(PaystackTransferRecipientGateway::class)->createRecipient(
            new RegisterPayoutAccountInput('0000000000', '057'),
        );
        test()->fail('Malformed provider identity should fail closed.');
    } catch (PaymentProviderException $exception) {
        $applicationFrames = array_filter(
            $exception->getTrace(),
            static fn (array $frame): bool => str_starts_with((string) ($frame['class'] ?? ''), 'App\\'),
        );
        $renderedTrace = json_encode($applicationFrames, JSON_THROW_ON_ERROR);

        expect($exception->failure)->toBe(PaymentProviderFailure::MalformedResponse)
            ->and($exception->getMessage())->not->toContain('0000000000')
            ->and($renderedTrace)->not->toContain('0000000000')
            ->and($renderedTrace)->not->toContain('sk_test_inert_recipient_key');
    }
})->with([
    'missing canonical name' => [[
        'status' => true,
        'data' => ['account_number' => '0000000000'],
    ], null],
    'blank canonical name' => [[
        'status' => true,
        'data' => ['account_number' => '0000000000', 'account_name' => '   '],
    ], null],
    'scalar canonical name' => [[
        'status' => true,
        'data' => ['account_number' => '0000000000', 'account_name' => 12345],
    ], null],
    'mismatched resolved number' => [[
        'status' => true,
        'data' => ['account_number' => '9999999999', 'account_name' => 'TEST CUSTOMER'],
    ], null],
    'scalar recipient details' => [successfulPaystackResolution(), [
        'status' => true,
        'data' => [
            ...successfulPaystackRecipient()['data'],
            'details' => 'not-an-object',
        ],
    ]],
    'list recipient details' => [successfulPaystackResolution(), [
        'status' => true,
        'data' => [
            ...successfulPaystackRecipient()['data'],
            'details' => ['not', 'an', 'object'],
        ],
    ]],
    'mismatched recipient bank' => [successfulPaystackResolution(), [
        'status' => true,
        'data' => [
            ...successfulPaystackRecipient()['data'],
            'details' => [
                ...successfulPaystackRecipient()['data']['details'],
                'bank_code' => '058',
            ],
        ],
    ]],
    'mismatched recipient canonical name' => [successfulPaystackResolution(), [
        'status' => true,
        'data' => [
            ...successfulPaystackRecipient()['data'],
            'name' => 'A DIFFERENT CUSTOMER',
        ],
    ]],
    'mismatched recipient account number' => [successfulPaystackResolution(), [
        'status' => true,
        'data' => [
            ...successfulPaystackRecipient()['data'],
            'details' => [
                ...successfulPaystackRecipient()['data']['details'],
                'account_number' => '9999999999',
            ],
        ],
    ]],
    'inactive recipient' => [successfulPaystackResolution(), [
        'status' => true,
        'data' => [
            ...successfulPaystackRecipient()['data'],
            'active' => false,
        ],
    ]],
    'string true recipient activity' => [successfulPaystackResolution(), [
        'status' => true,
        'data' => [
            ...successfulPaystackRecipient()['data'],
            'active' => 'true',
        ],
    ]],
    'wrong recipient type' => [successfulPaystackResolution(), [
        'status' => true,
        'data' => [
            ...successfulPaystackRecipient()['data'],
            'type' => 'mobile_money',
        ],
    ]],
    'wrong recipient currency' => [successfulPaystackResolution(), [
        'status' => true,
        'data' => [
            ...successfulPaystackRecipient()['data'],
            'currency' => 'GHS',
        ],
    ]],
    'missing recipient code' => [successfulPaystackResolution(), [
        'status' => true,
        'data' => [
            ...successfulPaystackRecipient()['data'],
            'recipient_code' => null,
        ],
    ]],
    'blank recipient code' => [successfulPaystackResolution(), [
        'status' => true,
        'data' => [
            ...successfulPaystackRecipient()['data'],
            'recipient_code' => '   ',
        ],
    ]],
    'scalar recipient code' => [successfulPaystackResolution(), [
        'status' => true,
        'data' => [
            ...successfulPaystackRecipient()['data'],
            'recipient_code' => 12345,
        ],
    ]],
    'missing bank name' => [successfulPaystackResolution(), [
        'status' => true,
        'data' => [
            ...successfulPaystackRecipient()['data'],
            'details' => [
                ...successfulPaystackRecipient()['data']['details'],
                'bank_name' => null,
            ],
        ],
    ]],
    'blank bank name' => [successfulPaystackResolution(), [
        'status' => true,
        'data' => [
            ...successfulPaystackRecipient()['data'],
            'details' => [
                ...successfulPaystackRecipient()['data']['details'],
                'bank_name' => '   ',
            ],
        ],
    ]],
]);

it('maps provider outage and timeout to sanitized synchronous failures', function (
    string $scenario,
    PaymentProviderFailure $failure,
): void {
    if ($scenario === 'timeout') {
        Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);
    } else {
        Http::fake(['*' => Http::response([
            'status' => false,
            'message' => 'System Malfunction',
        ], HttpResponse::HTTP_INTERNAL_SERVER_ERROR)]);
    }

    try {
        app(PaystackTransferRecipientGateway::class)->createRecipient(
            new RegisterPayoutAccountInput('0000000000', '057'),
        );
        test()->fail('The provider transport failure should be sanitized.');
    } catch (PaymentProviderException $exception) {
        expect($exception->failure)->toBe($failure)
            ->and($exception->getMessage())->not->toContain('0000000000');
    }
})->with([
    'server failure' => ['server', PaymentProviderFailure::Unavailable],
    'timeout' => ['timeout', PaymentProviderFailure::Timeout],
]);

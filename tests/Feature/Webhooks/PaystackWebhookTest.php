<?php

declare(strict_types=1);

use App\Actions\Cashback\HandlePaystackWebhook;
use App\Actions\Cashback\ProcessCashbackPayout;
use App\Actions\Cashback\RequestCashbackPayoutSupport;
use App\Contracts\Payments\CashbackTransferGateway;
use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payments\CashbackTransferResult;
use App\Data\Payments\TransferBalance;
use App\Enums\CashbackRewardStatus;
use App\Enums\CashbackTransferErrorCode;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PayoutStatus;
use App\Enums\ProviderWebhookReceiptResult;
use App\Http\Middleware\AssignRequestId;
use App\Infrastructure\Payments\PaymentProviderRegistry;
use App\Models\CashbackReward;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\ProviderWebhookReceipt;
use App\Models\User;
use App\Models\UserBadge;
use App\Notifications\CashbackPayoutRequiresAttention;
use Closure;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use LogicException;
use Mockery;
use Tests\TestCase;

uses(DatabaseMigrations::class);

final class CallbackWinningPaystackGateway implements CashbackTransferGateway
{
    /** @param Closure(CashbackTransferRequest): void $callback */
    public function __construct(private readonly Closure $callback) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Paystack;
    }

    public function availableBalance(Currency $currency): TransferBalance
    {
        return new TransferBalance(1_000_000, $currency);
    }

    public function initiateTransfer(CashbackTransferRequest $request): CashbackTransferResult
    {
        ($this->callback)($request);

        return new CashbackTransferResult(
            status: PayoutStatus::Pending,
            transferCode: 'TRF_STALE_RESPONSE',
            httpStatus: 200,
            latencyMs: 9,
        );
    }
}

/** @return array{CashbackReward, Payout} */
function paystackWebhookPayout(PayoutStatus $status = PayoutStatus::Pending): array
{
    $rewardStatus = match ($status) {
        PayoutStatus::Started,
        PayoutStatus::Ambiguous => CashbackRewardStatus::Processing,
        PayoutStatus::Pending => CashbackRewardStatus::Pending,
        PayoutStatus::Succeeded => CashbackRewardStatus::Paid,
        PayoutStatus::InsufficientFunds => CashbackRewardStatus::AwaitingFunds,
        PayoutStatus::RateLimited,
        PayoutStatus::Rejected,
        PayoutStatus::OtpRequired,
        PayoutStatus::Failed,
        PayoutStatus::Reversed => CashbackRewardStatus::RequiresAttention,
    };
    $user = User::factory()->create();
    $reward = CashbackReward::factory()
        ->for($user)
        ->for(UserBadge::factory()->for($user), 'userBadge')
        ->create([
            'status' => $rewardStatus,
            'paid_at' => $status === PayoutStatus::Succeeded ? now()->subMinute() : null,
        ]);
    $account = PayoutAccount::factory()->for($user)->create([
        'provider' => PaymentProvider::Paystack,
        'provider_recipient_code' => 'RCP_WEBHOOK_CUSTOMER',
    ]);
    $firstResultAt = $status === PayoutStatus::Started ? null : now()->subMinute();
    $transferCode = in_array($status, [
        PayoutStatus::Started,
        PayoutStatus::InsufficientFunds,
        PayoutStatus::RateLimited,
        PayoutStatus::Rejected,
    ], true) ? null : 'TRF_WEBHOOK_TRANSFER';
    $payout = Payout::factory()->create([
        'cashback_reward_id' => $reward->id,
        'payout_account_id' => $account->id,
        'provider' => PaymentProvider::Paystack,
        'provider_recipient_code' => $account->provider_recipient_code,
        'status' => $status,
        'provider_transfer_code' => $transferCode,
        'provider_error_code' => $status === PayoutStatus::RateLimited
            ? CashbackTransferErrorCode::RateLimited->value
            : null,
        'succeeded_at' => $status === PayoutStatus::Succeeded ? $firstResultAt : null,
        'reversed_at' => $status === PayoutStatus::Reversed ? $firstResultAt : null,
        'first_result_at' => $firstResultAt,
    ]);

    return [$reward, $payout];
}

/** @return array<string, mixed> */
function paystackWebhookPayload(
    CashbackReward $reward,
    Payout $payout,
    string $event = 'transfer.success',
    string $status = 'success',
): array {
    return [
        'event' => $event,
        'data' => [
            'reference' => $reward->provider_reference,
            'transfer_code' => $payout->provider_transfer_code ?? 'TRF_WEBHOOK_TRANSFER',
            'amount' => $reward->amount_minor,
            'currency' => $reward->currency->value,
            'source' => 'balance',
            'status' => $status,
            'recipient' => [
                'recipient_code' => $payout->provider_recipient_code,
            ],
            'reason' => 'raw provider prose must be ignored',
            'customer' => ['email' => 'private@example.test'],
        ],
    ];
}

function encodePaystackWebhook(array $payload, int $flags = JSON_PRESERVE_ZERO_FRACTION): string
{
    return json_encode($payload, JSON_THROW_ON_ERROR | $flags);
}

function paystackWebhookSignature(string $body, string $secret = 'sk_test_webhook_key'): string
{
    return hash_hmac('sha512', $body, $secret);
}

function postPaystackWebhook(
    TestCase $test,
    string $body,
    ?string $signature = null,
    bool $includeSignature = true,
): TestResponse {
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    if ($includeSignature) {
        $server['HTTP_X_PAYSTACK_SIGNATURE'] = $signature ?? paystackWebhookSignature($body);
    }

    return $test->call('POST', '/api/webhooks/paystack', server: $server, content: $body);
}

beforeEach(function (): void {
    config()->set('payments.paystack.secret_key', 'sk_test_webhook_key');
    config()->set('rewards.support_email', 'support@example.test');
    Http::preventStrayRequests();
    Notification::fake();
});

it('authenticates exact bytes and atomically applies a matching success callback', function (): void {
    [$reward, $payout] = paystackWebhookPayout();
    $firstResultAt = $payout->first_result_at;
    $body = encodePaystackWebhook(paystackWebhookPayload($reward, $payout));

    $response = postPaystackWebhook($this, $body);

    $response->assertOk()->assertContent('')->assertHeader(AssignRequestId::HEADER);
    $reward->refresh();
    $payout->refresh();
    $receipt = ProviderWebhookReceipt::query()->sole();

    expect($receipt->provider)->toBe(PaymentProvider::Paystack)
        ->and($receipt->body_hash)->toBe(hash('sha256', $body))
        ->and($receipt->event_type)->toBe('transfer.success')
        ->and($receipt->provider_reference)->toBe($reward->provider_reference)
        ->and($receipt->payout_id)->toBe($payout->id)
        ->and($receipt->result)->toBe(ProviderWebhookReceiptResult::Applied)
        ->and($payout->status)->toBe(PayoutStatus::Succeeded)
        ->and($payout->provider_transfer_code)->toBe('TRF_WEBHOOK_TRANSFER')
        ->and($payout->succeeded_at)->not->toBeNull()
        ->and($payout->first_result_at?->equalTo($firstResultAt))->toBeTrue()
        ->and($payout->provider_error_code)->toBeNull()
        ->and($reward->status)->toBe(CashbackRewardStatus::Paid)
        ->and($reward->paid_at)->not->toBeNull()
        ->and(Payout::query()->count())->toBe(1);
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

it('applies a matching success callback from every open payout state', function (
    PayoutStatus $initialStatus,
): void {
    [$reward, $payout] = paystackWebhookPayout($initialStatus);
    $body = encodePaystackWebhook(paystackWebhookPayload($reward, $payout));

    postPaystackWebhook($this, $body)->assertOk();

    expect(ProviderWebhookReceipt::query()->sole()->result)
        ->toBe(ProviderWebhookReceiptResult::Applied)
        ->and($payout->fresh()?->status)->toBe(PayoutStatus::Succeeded)
        ->and($reward->fresh()?->status)->toBe(CashbackRewardStatus::Paid);
})->with([
    'started' => PayoutStatus::Started,
    'ambiguous' => PayoutStatus::Ambiguous,
    'OTP required' => PayoutStatus::OtpRequired,
]);

it('rejects missing malformed mismatched and byte-mutated signatures without persistence', function (
    string $signatureKind,
): void {
    [$reward, $payout] = paystackWebhookPayout();
    $body = encodePaystackWebhook(paystackWebhookPayload($reward, $payout));
    [$sentBody, $signature, $includeSignature] = match ($signatureKind) {
        'missing' => [$body, null, false],
        'uppercase' => [$body, strtoupper(paystackWebhookSignature($body)), true],
        'wrong' => [$body, str_repeat('a', 128), true],
        'mutated bytes' => [$body.' ', paystackWebhookSignature($body), true],
    };

    $response = postPaystackWebhook($this, $sentBody, $signature, $includeSignature);

    $response
        ->assertUnauthorized()
        ->assertJsonPath('code', 'invalid_webhook_signature')
        ->assertHeaderMissing('WWW-Authenticate');
    expect(ProviderWebhookReceipt::query()->count())->toBe(0)
        ->and($payout->fresh()?->status)->toBe(PayoutStatus::Pending)
        ->and($reward->fresh()?->status)->toBe(CashbackRewardStatus::Pending);
    Notification::assertNothingSent();
})->with(['missing', 'uppercase', 'wrong', 'mutated bytes']);

it('rejects oversized bodies before parsing or persistence', function (): void {
    $body = str_repeat('x', 65_537);

    postPaystackWebhook($this, $body)
        ->assertStatus(413)
        ->assertJsonPath('code', 'webhook_payload_too_large');

    expect(ProviderWebhookReceipt::query()->count())->toBe(0);
});

it('accepts the exact maximum body size', function (): void {
    $body = '{}'.str_repeat(' ', 65_534);

    postPaystackWebhook($this, $body)->assertOk()->assertContent('');

    $receipt = ProviderWebhookReceipt::query()->sole();

    expect(strlen($body))->toBe(65_536)
        ->and($receipt->body_hash)->toBe(hash('sha256', $body))
        ->and($receipt->result)->toBe(ProviderWebhookReceiptResult::Invalid);
});

it('fails closed when the configured key is blank malformed or live', function (?string $secret): void {
    config()->set('payments.paystack.secret_key', $secret);
    $body = '{}';

    postPaystackWebhook($this, $body, paystackWebhookSignature($body))
        ->assertServiceUnavailable()
        ->assertJsonPath('code', 'webhook_verification_unavailable');

    expect(ProviderWebhookReceipt::query()->count())->toBe(0);
})->with([
    'blank' => null,
    'malformed' => 'test_secret',
    'live mode' => 'sk_live_must_not_activate',
]);

it('fails closed when trusted configuration contains a non-string key', function (mixed $secret): void {
    config()->set('payments.paystack.secret_key', $secret);

    postPaystackWebhook($this, '{}', str_repeat('a', 128))
        ->assertServiceUnavailable()
        ->assertJsonPath('code', 'webhook_verification_unavailable');

    expect(ProviderWebhookReceipt::query()->count())->toBe(0);
})->with([
    'boolean' => true,
    'integer' => 123,
    'array' => [['sk_test_not_a_scalar']],
]);

it('records authentic malformed input and safe unsupported events without mutating payouts', function (
    string $kind,
    string $body,
    ProviderWebhookReceiptResult $expectedResult,
    ?string $expectedEvent,
): void {
    [$reward, $payout] = paystackWebhookPayout();

    $body = str_replace(['__REFERENCE__', '__RECIPIENT__'], [
        $reward->provider_reference,
        $payout->provider_recipient_code,
    ], $body);

    postPaystackWebhook($this, $body)->assertOk();
    postPaystackWebhook($this, $body)->assertOk();

    $receipt = ProviderWebhookReceipt::query()->sole();
    expect($receipt->result)->toBe($expectedResult)
        ->and($receipt->event_type)->toBe($expectedEvent)
        ->and($receipt->payout_id)->toBeNull()
        ->and($payout->fresh()?->status)->toBe(PayoutStatus::Pending)
        ->and($reward->fresh()?->status)->toBe(CashbackRewardStatus::Pending)
        ->and($kind)->toBeString();
    Notification::assertNothingSent();
})->with([
    'malformed JSON' => ['malformed', '{', ProviderWebhookReceiptResult::Invalid, null],
    'root list' => ['list', '[]', ProviderWebhookReceiptResult::Invalid, null],
    'missing event' => ['missing event', '{}', ProviderWebhookReceiptResult::Invalid, null],
    'null event' => ['null event', '{"event":null}', ProviderWebhookReceiptResult::Invalid, null],
    'numeric event' => ['numeric event', '{"event":1}', ProviderWebhookReceiptResult::Invalid, null],
    'blank event' => ['blank event', '{"event":""}', ProviderWebhookReceiptResult::Invalid, null],
    'unsafe event' => ['unsafe', '{"event":" transfer.success"}', ProviderWebhookReceiptResult::Invalid, null],
    'maximum-size unsupported event' => [
        'maximum event',
        json_encode(['event' => str_repeat('e', 100)], JSON_THROW_ON_ERROR),
        ProviderWebhookReceiptResult::Unsupported,
        str_repeat('e', 100),
    ],
    'overlong event' => [
        'overlong event',
        json_encode(['event' => str_repeat('e', 101)], JSON_THROW_ON_ERROR),
        ProviderWebhookReceiptResult::Invalid,
        null,
    ],
    'safe unsupported event ignores data' => [
        'unsupported',
        '{"event":"transfer.pending","data":["private"]}',
        ProviderWebhookReceiptResult::Unsupported,
        'transfer.pending',
    ],
    'contradictory supported pair' => [
        'contradictory',
        '{"event":"transfer.success","data":{"reference":"__REFERENCE__","transfer_code":"TRF_WEBHOOK_TRANSFER","amount":30000,"currency":"NGN","source":"balance","status":"failed","recipient":{"recipient_code":"__RECIPIENT__"}}}',
        ProviderWebhookReceiptResult::Invalid,
        'transfer.success',
    ],
]);

it('strictly rejects wrong callback shapes and scalar types without coercion', function (Closure $mutate): void {
    [$reward, $payout] = paystackWebhookPayout();
    $payload = paystackWebhookPayload($reward, $payout);
    $mutate($payload);

    postPaystackWebhook($this, encodePaystackWebhook($payload))->assertOk();

    expect(ProviderWebhookReceipt::query()->sole()->result)
        ->toBe(ProviderWebhookReceiptResult::Invalid)
        ->and($payout->fresh()?->status)->toBe(PayoutStatus::Pending)
        ->and($reward->fresh()?->status)->toBe(CashbackRewardStatus::Pending);
})->with([
    'data list' => [static function (array &$payload): void {
        $payload['data'] = [];
    }],
    'recipient list' => [static function (array &$payload): void {
        $payload['data']['recipient'] = [];
    }],
    'string amount' => [static function (array &$payload): void {
        $payload['data']['amount'] = '30000';
    }],
    'floating amount' => [static function (array &$payload): void {
        $payload['data']['amount'] = 30000.0;
    }],
    'zero amount' => [static function (array &$payload): void {
        $payload['data']['amount'] = 0;
    }],
    'wrong currency' => [static function (array &$payload): void {
        $payload['data']['currency'] = 'USD';
    }],
    'wrong source' => [static function (array &$payload): void {
        $payload['data']['source'] = 'bank';
    }],
    'missing reference' => [static function (array &$payload): void {
        unset($payload['data']['reference']);
    }],
    'null transfer code' => [static function (array &$payload): void {
        $payload['data']['transfer_code'] = null;
    }],
    'edge-whitespace recipient' => [static function (array &$payload): void {
        $payload['data']['recipient']['recipient_code'] = ' RCP_WEBHOOK_CUSTOMER';
    }],
    'control byte reference' => [static function (array &$payload): void {
        $payload['data']['reference'] = "callback\nreference";
    }],
    'overlong transfer code' => [static function (array &$payload): void {
        $payload['data']['transfer_code'] = str_repeat('T', 256);
    }],
]);

it('distinguishes unknown references from mismatched stored payout facts', function (
    Closure $mutate,
    ProviderWebhookReceiptResult $expectedResult,
): void {
    [$reward, $payout] = paystackWebhookPayout();
    $payload = paystackWebhookPayload($reward, $payout);
    $mutate($payload);

    postPaystackWebhook($this, encodePaystackWebhook($payload))->assertOk();

    $receipt = ProviderWebhookReceipt::query()->sole();
    expect($receipt->result)->toBe($expectedResult)
        ->and($receipt->payout_id)->toBeNull()
        ->and($payout->fresh()?->status)->toBe(PayoutStatus::Pending)
        ->and($reward->fresh()?->status)->toBe(CashbackRewardStatus::Pending);
})->with([
    'unknown reference' => [
        static function (array &$payload): void {
            $payload['data']['reference'] = 'cashback-missing-reference';
        },
        ProviderWebhookReceiptResult::NotFound,
    ],
    'recipient mismatch' => [
        static function (array &$payload): void {
            $payload['data']['recipient']['recipient_code'] = 'RCP_OTHER_CUSTOMER';
        },
        ProviderWebhookReceiptResult::Mismatch,
    ],
    'amount mismatch' => [
        static function (array &$payload): void {
            $payload['data']['amount']++;
        },
        ProviderWebhookReceiptResult::Mismatch,
    ],
    'known transfer-code mismatch' => [
        static function (array &$payload): void {
            $payload['data']['transfer_code'] = 'TRF_OTHER_TRANSFER';
        },
        ProviderWebhookReceiptResult::Mismatch,
    ],
]);

it('matches callbacks against the payout provider snapshot after the account changes', function (): void {
    [$reward, $payout] = paystackWebhookPayout();
    $payout->payoutAccount->update([
        'provider' => PaymentProvider::Fake,
        'provider_recipient_code' => 'RCP_REPLACED_AFTER_PAYOUT',
    ]);
    $body = encodePaystackWebhook(paystackWebhookPayload($reward, $payout));

    postPaystackWebhook($this, $body)->assertOk();

    expect(ProviderWebhookReceipt::query()->sole()->result)
        ->toBe(ProviderWebhookReceiptResult::Applied)
        ->and($payout->fresh()?->provider)->toBe(PaymentProvider::Paystack)
        ->and($payout->fresh()?->status)->toBe(PayoutStatus::Succeeded);
});

it('rejects a Paystack callback when the payout belongs to another provider', function (): void {
    [$reward, $payout] = paystackWebhookPayout();
    $payout->update(['provider' => PaymentProvider::Fake]);
    $body = encodePaystackWebhook(paystackWebhookPayload($reward, $payout));

    postPaystackWebhook($this, $body)->assertOk();

    expect(ProviderWebhookReceipt::query()->sole()->result)
        ->toBe(ProviderWebhookReceiptResult::Mismatch)
        ->and($payout->fresh()?->status)->toBe(PayoutStatus::Pending)
        ->and($reward->fresh()?->status)->toBe(CashbackRewardStatus::Pending);
});

it('deduplicates exact delivery but records a byte-different semantic duplicate as unchanged', function (): void {
    Log::spy();
    [$reward, $payout] = paystackWebhookPayout();
    $payload = paystackWebhookPayload($reward, $payout);
    $compact = encodePaystackWebhook($payload);
    $pretty = encodePaystackWebhook($payload, JSON_PRETTY_PRINT);

    postPaystackWebhook($this, $compact)->assertOk();
    postPaystackWebhook($this, $compact)->assertOk();
    postPaystackWebhook($this, $pretty)->assertOk();

    expect(ProviderWebhookReceipt::query()->count())->toBe(2)
        ->and(ProviderWebhookReceipt::query()->orderBy('id')->pluck('result')->all())->toBe([
            ProviderWebhookReceiptResult::Applied,
            ProviderWebhookReceiptResult::Unchanged,
        ])
        ->and($payout->fresh()?->status)->toBe(PayoutStatus::Succeeded)
        ->and($reward->fresh()?->status)->toBe(CashbackRewardStatus::Paid);
    Log::shouldHaveReceived('info')->once()->with('paystack.webhook.recorded', Mockery::type('array'));
    Log::shouldHaveReceived('debug')->once()->with('paystack.webhook.recorded', Mockery::type('array'));
    Notification::assertNothingSent();
});

it('logs each receipt result at its documented severity', function (
    string $kind,
    ProviderWebhookReceiptResult $expectedResult,
    string $expectedLevel,
): void {
    Log::spy();
    [$reward, $payout] = paystackWebhookPayout();
    $payload = paystackWebhookPayload($reward, $payout);
    $body = match ($kind) {
        'unsupported' => '{"event":"transfer.pending"}',
        'invalid' => '{"event":null}',
        'not found' => encodePaystackWebhook(array_replace_recursive($payload, [
            'data' => ['reference' => 'cashback-missing-reference'],
        ])),
        'mismatch' => encodePaystackWebhook(array_replace_recursive($payload, [
            'data' => ['amount' => $reward->amount_minor + 1],
        ])),
    };

    postPaystackWebhook($this, $body)->assertOk();

    expect(ProviderWebhookReceipt::query()->sole()->result)->toBe($expectedResult);
    Log::shouldHaveReceived($expectedLevel)
        ->once()
        ->with('paystack.webhook.recorded', Mockery::type('array'));
})->with([
    'unsupported is debug' => [
        'unsupported',
        ProviderWebhookReceiptResult::Unsupported,
        'debug',
    ],
    'invalid is warning' => [
        'invalid',
        ProviderWebhookReceiptResult::Invalid,
        'warning',
    ],
    'not found is warning' => [
        'not found',
        ProviderWebhookReceiptResult::NotFound,
        'warning',
    ],
    'mismatch is warning' => [
        'mismatch',
        ProviderWebhookReceiptResult::Mismatch,
        'warning',
    ],
]);

it('applies failure and reversal facts with safe local errors and one support request', function (
    string $event,
    string $providerStatus,
    PayoutStatus $expectedStatus,
    CashbackTransferErrorCode $expectedError,
): void {
    [$reward, $payout] = paystackWebhookPayout();
    $body = encodePaystackWebhook(
        paystackWebhookPayload($reward, $payout, $event, $providerStatus),
    );

    postPaystackWebhook($this, $body)->assertOk();
    postPaystackWebhook($this, $body)->assertOk();

    $reward->refresh();
    $payout->refresh();
    expect($payout->status)->toBe($expectedStatus)
        ->and($payout->provider_error_code)->toBe($expectedError->value)
        ->and($payout->provider_error_message)->not->toContain('raw provider prose')
        ->and($payout->support_alert_requested_at)->not->toBeNull()
        ->and($reward->status)->toBe(CashbackRewardStatus::RequiresAttention)
        ->and($reward->paid_at)->toBeNull();
    expect(ProviderWebhookReceipt::query()->count())->toBe(1);
    Notification::assertSentOnDemandTimes(CashbackPayoutRequiresAttention::class, 1);
})->with([
    'failure' => [
        'transfer.failed',
        'failed',
        PayoutStatus::Failed,
        CashbackTransferErrorCode::TransferFailed,
    ],
    'reversal before local success' => [
        'transfer.reversed',
        'reversed',
        PayoutStatus::Reversed,
        CashbackTransferErrorCode::TransferReversed,
    ],
]);

it('accepts only reversal after success and preserves the original success timestamp', function (): void {
    [$reward, $payout] = paystackWebhookPayout(PayoutStatus::Succeeded);
    $succeededAt = $payout->succeeded_at;
    $failedBody = encodePaystackWebhook(
        paystackWebhookPayload($reward, $payout, 'transfer.failed', 'failed'),
    );
    $reversedBody = encodePaystackWebhook(
        paystackWebhookPayload($reward, $payout, 'transfer.reversed', 'reversed'),
    );

    postPaystackWebhook($this, $failedBody)->assertOk();
    expect($payout->fresh()?->status)->toBe(PayoutStatus::Succeeded)
        ->and(ProviderWebhookReceipt::query()->latest('id')->firstOrFail()->result)
        ->toBe(ProviderWebhookReceiptResult::Unchanged);

    postPaystackWebhook($this, $reversedBody)->assertOk();
    $payout->refresh();
    $reward->refresh();

    expect($payout->status)->toBe(PayoutStatus::Reversed)
        ->and($payout->succeeded_at?->equalTo($succeededAt))->toBeTrue()
        ->and($payout->reversed_at)->not->toBeNull()
        ->and($reward->status)->toBe(CashbackRewardStatus::RequiresAttention)
        ->and($reward->paid_at)->toBeNull();
    Notification::assertSentOnDemandTimes(CashbackPayoutRequiresAttention::class, 1);
});

it('retains a safe reference for an unsupported event without interpreting its payout data', function (): void {
    [$reward, $payout] = paystackWebhookPayout();
    $body = encodePaystackWebhook([
        'event' => 'transfer.pending',
        'data' => [
            'reference' => $reward->provider_reference,
            'recipient' => ['recipient_code' => 'PRIVATE_UNTRUSTED_VALUE'],
            'reason' => 'PRIVATE_PROVIDER_REASON',
        ],
    ]);

    postPaystackWebhook($this, $body)->assertOk();

    $receipt = ProviderWebhookReceipt::query()->sole();
    expect($receipt->result)->toBe(ProviderWebhookReceiptResult::Unsupported)
        ->and($receipt->event_type)->toBe('transfer.pending')
        ->and($receipt->provider_reference)->toBe($reward->provider_reference)
        ->and($receipt->payout_id)->toBeNull()
        ->and($payout->fresh()?->status)->toBe(PayoutStatus::Pending);
    Notification::assertNothingSent();
});

it('leaves a matching payout unchanged when reward and payout statuses disagree', function (
    PayoutStatus $payoutStatus,
    CashbackRewardStatus $wrongRewardStatus,
    string $event,
    string $transferStatus,
): void {
    [$reward, $payout] = paystackWebhookPayout($payoutStatus);
    $reward->update(['status' => $wrongRewardStatus]);
    $body = encodePaystackWebhook(
        paystackWebhookPayload($reward, $payout, $event, $transferStatus),
    );

    postPaystackWebhook($this, $body)->assertOk();

    expect(ProviderWebhookReceipt::query()->sole()->result)
        ->toBe(ProviderWebhookReceiptResult::Unchanged)
        ->and($payout->fresh()?->status)->toBe($payoutStatus)
        ->and($reward->fresh()?->status)->toBe($wrongRewardStatus);
    Notification::assertNothingSent();
})->with([
    'started payout with pending reward' => [
        PayoutStatus::Started,
        CashbackRewardStatus::Pending,
        'transfer.success',
        'success',
    ],
    'pending payout with processing reward' => [
        PayoutStatus::Pending,
        CashbackRewardStatus::Processing,
        'transfer.success',
        'success',
    ],
    'OTP payout with processing reward' => [
        PayoutStatus::OtpRequired,
        CashbackRewardStatus::Processing,
        'transfer.success',
        'success',
    ],
    'succeeded payout with processing reward' => [
        PayoutStatus::Succeeded,
        CashbackRewardStatus::Processing,
        'transfer.reversed',
        'reversed',
    ],
]);

it('still attempts support when the committed callback receipt log fails', function (): void {
    Log::spy();
    Log::shouldReceive('info')
        ->once()
        ->with('paystack.webhook.recorded', Mockery::type('array'))
        ->andThrow(new RuntimeException('log unavailable'));
    [$reward, $payout] = paystackWebhookPayout();
    $body = encodePaystackWebhook(
        paystackWebhookPayload($reward, $payout, 'transfer.failed', 'failed'),
    );

    postPaystackWebhook($this, $body)->assertOk();

    expect($payout->fresh()?->status)->toBe(PayoutStatus::Failed)
        ->and($payout->fresh()?->support_alert_requested_at)->not->toBeNull();
    Notification::assertSentOnDemandTimes(CashbackPayoutRequiresAttention::class, 1);
});

it('does not reopen failed reversed or statuses where no transfer was created', function (
    PayoutStatus $initialStatus,
): void {
    [$reward, $payout] = paystackWebhookPayout($initialStatus);
    $body = encodePaystackWebhook(paystackWebhookPayload($reward, $payout));

    postPaystackWebhook($this, $body)->assertOk();

    expect($payout->fresh()?->status)->toBe($initialStatus)
        ->and(ProviderWebhookReceipt::query()->sole()->result)
        ->toBe(ProviderWebhookReceiptResult::Unchanged);
    Notification::assertNothingSent();
})->with([
    PayoutStatus::Failed,
    PayoutStatus::Reversed,
    PayoutStatus::InsufficientFunds,
    PayoutStatus::RateLimited,
    PayoutStatus::Rejected,
]);

it('lets a real callback win before the older initiation response is stored', function (): void {
    Log::spy();
    $user = User::factory()->create();
    PayoutAccount::factory()->for($user)->create([
        'provider' => PaymentProvider::Paystack,
        'provider_recipient_code' => 'RCP_RACE_CUSTOMER',
    ]);
    $reward = CashbackReward::factory()
        ->for($user)
        ->for(UserBadge::factory()->for($user), 'userBadge')
        ->readyForPayout()
        ->create();
    $gateway = new CallbackWinningPaystackGateway(
        function (CashbackTransferRequest $request): void {
            $payload = [
                'event' => 'transfer.success',
                'data' => [
                    'reference' => $request->providerReference,
                    'transfer_code' => 'TRF_CALLBACK_WON',
                    'amount' => $request->amountMinor,
                    'currency' => $request->currency->value,
                    'source' => 'balance',
                    'status' => 'success',
                    'recipient' => ['recipient_code' => $request->recipientCode],
                ],
            ];
            $body = encodePaystackWebhook($payload);
            app(HandlePaystackWebhook::class)->handle($body, paystackWebhookSignature($body));
        },
    );
    $action = new ProcessCashbackPayout(
        new PaymentProviderRegistry([], [$gateway], PaymentProvider::Paystack->value),
        app(RequestCashbackPayoutSupport::class),
    );

    $payout = $action->handle($reward->id);
    $reward->refresh();

    expect($payout?->status)->toBe(PayoutStatus::Succeeded)
        ->and($payout?->provider_transfer_code)->toBe('TRF_CALLBACK_WON')
        ->and($payout?->first_result_at)->not->toBeNull()
        ->and($reward->status)->toBe(CashbackRewardStatus::Paid)
        ->and(ProviderWebhookReceipt::query()->sole()->result)
        ->toBe(ProviderWebhookReceiptResult::Applied);
    Log::shouldHaveReceived('info')->with(
        'cashback.payout.processed',
        Mockery::on(fn (array $context): bool => $context['state_changed'] === false
            && $context['payout_status'] === PayoutStatus::Succeeded->value
            && $context['provider_http_status'] === null
            && $context['provider_latency_ms'] === null),
    )->once();
});

it('rolls back payout and receipt together and emits no milestone log on database failure', function (): void {
    Log::spy();
    [$reward, $payout] = paystackWebhookPayout();
    $body = encodePaystackWebhook(paystackWebhookPayload($reward, $payout));

    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION fail_applied_webhook_receipt()
        RETURNS trigger AS $$
        BEGIN
            IF NEW.result = 'applied' THEN
                RAISE EXCEPTION 'simulated receipt update failure';
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql
        SQL);
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER fail_applied_webhook_receipt
        BEFORE UPDATE ON provider_webhook_receipts
        FOR EACH ROW EXECUTE FUNCTION fail_applied_webhook_receipt()
        SQL);

    try {
        postPaystackWebhook($this, $body)
            ->assertInternalServerError()
            ->assertJsonPath('code', 'internal_server_error');
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS fail_applied_webhook_receipt ON provider_webhook_receipts');
        DB::unprepared('DROP FUNCTION IF EXISTS fail_applied_webhook_receipt()');
    }

    expect(ProviderWebhookReceipt::query()->count())->toBe(0)
        ->and($payout->fresh()?->status)->toBe(PayoutStatus::Pending)
        ->and($reward->fresh()?->status)->toBe(CashbackRewardStatus::Pending)
        ->and($payout->fresh()?->support_alert_requested_at)->toBeNull();
    Log::shouldNotHaveReceived('info', ['paystack.webhook.recorded', Mockery::type('array')]);
    Log::shouldNotHaveReceived('debug', ['paystack.webhook.recorded', Mockery::type('array')]);
    Log::shouldNotHaveReceived('warning', ['paystack.webhook.recorded', Mockery::type('array')]);
    Notification::assertNothingSent();
});

it('logs each new receipt after commit with only the approved local fields and context', function (): void {
    [$reward, $payout] = paystackWebhookPayout();
    $body = encodePaystackWebhook(paystackWebhookPayload($reward, $payout));
    Log::shouldReceive('info')->once()->with(
        'paystack.webhook.recorded',
        Mockery::on(function (array $context) use ($reward, $payout): bool {
            return array_keys($context) === [
                'receipt_id',
                'event_type',
                'result',
                'cashback_reward_id',
                'payout_id',
                'old_payout_status',
                'new_payout_status',
                'reward_status',
                'correlation_id',
            ]
                && $context['cashback_reward_id'] === $reward->id
                && $context['payout_id'] === $payout->id
                && $context['correlation_id'] === $reward->correlation_id
                && Context::get(AssignRequestId::ATTRIBUTE) !== null
                && Context::get('correlation_id') === $reward->correlation_id
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $reward->provider_reference)
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $payout->provider_recipient_code);
        }),
    );

    postPaystackWebhook($this, $body)->assertOk();
});

it('is POST-only public and rejects caller-owned transactions', function (): void {
    $this->getJson('/api/webhooks/paystack')->assertMethodNotAllowed();
    postPaystackWebhook($this, '{}', includeSignature: false)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'invalid_webhook_signature');

    expect(fn () => DB::transaction(
        fn () => app(HandlePaystackWebhook::class)->handle(
            '{}',
            paystackWebhookSignature('{}'),
        ),
    ))->toThrow(
        LogicException::class,
        'Paystack webhook handling cannot run inside an existing database transaction.',
    );
});

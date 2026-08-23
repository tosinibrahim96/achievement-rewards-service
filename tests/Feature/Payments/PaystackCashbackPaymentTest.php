<?php

declare(strict_types=1);

use App\Actions\Cashback\ProcessCashbackPayment;
use App\Enums\CashbackRewardStatus;
use App\Enums\PaymentProvider;
use App\Enums\PayoutAttemptStatus;
use App\Models\CashbackReward;
use App\Models\PayoutAccount;
use App\Models\PayoutAttempt;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

uses(DatabaseMigrations::class);

/** @return array{CashbackReward, PayoutAccount} */
function payablePaystackRewardForTest(): array
{
    $user = User::factory()->create();
    $userBadge = UserBadge::factory()->for($user)->create();
    $reward = CashbackReward::factory()
        ->for($user)
        ->for($userBadge, 'userBadge')
        ->create();
    $account = PayoutAccount::factory()->for($user)->create([
        'provider' => PaymentProvider::Paystack,
        'provider_recipient_code' => 'RCP_paystack_processor',
        'bank_name' => 'Zenith Bank',
        'account_name' => 'TEST CUSTOMER',
    ]);

    return [$reward, $account];
}

beforeEach(function (): void {
    config()->set('payments.default', PaymentProvider::Fake->value);
    config()->set('payments.paystack.secret_key', 'sk_test_inert_processor_key');
    Http::preventStrayRequests();
    Notification::fake();
});

it('persists real-adapter outcomes against the Paystack-owned attempt without using the fake default', function (
    string $scenario,
    PayoutAttemptStatus $attemptStatus,
    CashbackRewardStatus $rewardStatus,
    ?string $errorCode,
): void {
    [$reward, $account] = payablePaystackRewardForTest();

    if ($scenario === 'timeout') {
        Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);
    } elseif ($scenario === 'insufficient') {
        Http::fake(['*' => Http::response([
            'status' => false,
            'message' => 'Your balance is not enough to fulfill this request',
        ], HttpResponse::HTTP_BAD_REQUEST)]);
    } elseif ($scenario === 'rate_limit') {
        Http::fake(['*' => Http::response([
            'status' => false,
            'message' => 'Rate limit exceeded',
            'code' => 'rate_limited',
        ], HttpResponse::HTTP_TOO_MANY_REQUESTS)]);
    } else {
        Http::fake(['*' => Http::response([
            'status' => true,
            'message' => 'Transfer has been queued',
            'data' => [
                'reference' => $reward->provider_reference,
                'amount' => $reward->amount_minor,
                'currency' => $reward->currency->value,
                'source' => 'balance',
                'status' => $scenario,
                'transfer_code' => 'TRF_paystack_processor',
            ],
        ])]);
    }

    $attempt = app(ProcessCashbackPayment::class)->handle($reward->id);
    $reward->refresh();

    expect($attempt?->status)->toBe($attemptStatus)
        ->and($attempt?->provider)->toBe(PaymentProvider::Paystack)
        ->and($attempt?->payout_account_id)->toBe($account->id)
        ->and($attempt?->provider_recipient_code)->toBe('RCP_paystack_processor')
        ->and($attempt?->provider_error_code)->toBe($errorCode)
        ->and($attempt?->completed_at)->not->toBeNull()
        ->and($reward->provider)->toBe(PaymentProvider::Paystack)
        ->and($reward->status)->toBe($rewardStatus)
        ->and(PayoutAttempt::query()->whereBelongsTo($reward, 'cashbackReward')->count())->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.paystack.co/transfer'
        && $request->data()['reference'] === $reward->provider_reference);
    Http::assertSentCount(1);

    expect(app(ProcessCashbackPayment::class)->handle($reward->id))->toBeNull();
    Http::assertSentCount(1);
})->with([
    'test success' => ['success', PayoutAttemptStatus::Succeeded, CashbackRewardStatus::Paid, null],
    'live-like pending' => ['pending', PayoutAttemptStatus::Pending, CashbackRewardStatus::Pending, null],
    'unexpected OTP' => ['otp', PayoutAttemptStatus::OtpRequired, CashbackRewardStatus::RequiresAttention, 'otp_required'],
    'insufficient funds' => ['insufficient', PayoutAttemptStatus::InsufficientFunds, CashbackRewardStatus::AwaitingFunds, 'insufficient_funds'],
    'rate limited needs attention without a retry worker' => ['rate_limit', PayoutAttemptStatus::RetryableRejection, CashbackRewardStatus::RequiresAttention, 'rate_limited'],
    'timeout ambiguity' => ['timeout', PayoutAttemptStatus::Ambiguous, CashbackRewardStatus::Processing, 'provider_timeout'],
]);

it('does not fall back to fake when a persisted Paystack obligation has no credential', function (): void {
    config()->set('payments.paystack.secret_key');
    [$reward] = payablePaystackRewardForTest();
    Http::fake();

    $attempt = app(ProcessCashbackPayment::class)->handle($reward->id);
    $reward->refresh();

    expect($attempt?->provider)->toBe(PaymentProvider::Paystack)
        ->and($attempt?->status)->toBe(PayoutAttemptStatus::PermanentRejection)
        ->and($attempt?->provider_error_code)->toBe('provider_unavailable')
        ->and($reward->provider)->toBe(PaymentProvider::Paystack)
        ->and($reward->status)->toBe(CashbackRewardStatus::RequiresAttention);
    Http::assertNothingSent();
});

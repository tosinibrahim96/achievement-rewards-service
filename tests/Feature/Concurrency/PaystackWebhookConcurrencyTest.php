<?php

declare(strict_types=1);

use App\Actions\Cashback\HandlePaystackWebhook;
use App\Enums\CashbackRewardStatus;
use App\Enums\PaymentProvider;
use App\Enums\PayoutAttemptStatus;
use App\Enums\ProviderWebhookReceiptResult;
use App\Models\CashbackReward;
use App\Models\PayoutAccount;
use App\Models\PayoutAttempt;
use App\Models\ProviderWebhookReceipt;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\ConcurrentRunner;

uses(DatabaseMigrations::class);

it('deduplicates two concurrent deliveries of the exact signed body', function (): void {
    config()->set('payments.paystack.secret_key', 'sk_test_concurrent_webhook_key');
    $user = User::factory()->create();
    $reward = CashbackReward::factory()
        ->for($user)
        ->for(UserBadge::factory()->for($user), 'userBadge')
        ->create([
            'provider' => PaymentProvider::Paystack,
            'status' => CashbackRewardStatus::Pending,
            'last_attempted_at' => now()->subMinute(),
        ]);
    $account = PayoutAccount::factory()->for($user)->create([
        'provider' => PaymentProvider::Paystack,
        'provider_recipient_code' => 'RCP_CONCURRENT_WEBHOOK',
    ]);
    $attempt = PayoutAttempt::factory()->create([
        'cashback_reward_id' => $reward->id,
        'payout_account_id' => $account->id,
        'provider' => PaymentProvider::Paystack,
        'provider_recipient_code' => $account->provider_recipient_code,
        'status' => PayoutAttemptStatus::Pending,
        'provider_transfer_code' => 'TRF_CONCURRENT_WEBHOOK',
        'completed_at' => now()->subMinute(),
    ]);
    $body = json_encode([
        'event' => 'transfer.success',
        'data' => [
            'reference' => $reward->provider_reference,
            'transfer_code' => $attempt->provider_transfer_code,
            'amount' => $reward->amount_minor,
            'currency' => $reward->currency->value,
            'source' => 'balance',
            'status' => 'success',
            'recipient' => ['recipient_code' => $attempt->provider_recipient_code],
        ],
    ], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha512', $body, 'sk_test_concurrent_webhook_key');

    (new ConcurrentRunner)->run([
        static fn () => app(HandlePaystackWebhook::class)->handle($body, $signature),
        static fn () => app(HandlePaystackWebhook::class)->handle($body, $signature),
    ]);

    $reward->refresh();
    $attempt->refresh();

    expect(ProviderWebhookReceipt::query()->count())->toBe(1)
        ->and(ProviderWebhookReceipt::query()->sole()->result)
        ->toBe(ProviderWebhookReceiptResult::Applied)
        ->and($attempt->status)->toBe(PayoutAttemptStatus::Succeeded)
        ->and($reward->status)->toBe(CashbackRewardStatus::Paid);
});

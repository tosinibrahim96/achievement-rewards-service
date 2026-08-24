<?php

declare(strict_types=1);

use App\Actions\Achievements\EvaluatePurchaseAchievements;
use App\Actions\Achievements\GetUserAchievementProgress;
use App\Actions\Achievements\UnlockAchievement;
use App\Actions\Badges\EvaluateBadges;
use App\Actions\Badges\UnlockBadge;
use App\Actions\Cashback\CreateCashbackReward;
use App\Actions\Cashback\HandlePaystackWebhook;
use App\Actions\Cashback\ListCashbackRewards;
use App\Actions\Cashback\ProcessCashbackPayout;
use App\Actions\Cashback\QueueCashbackPayout;
use App\Actions\Cashback\QueueCashbackPayouts;
use App\Actions\Cashback\RequestCashbackPayoutSupport;
use App\Actions\Cashback\VerifyPaystackWebhookSignature;
use App\Actions\Payouts\RegisterPayoutAccount;
use App\Actions\Purchases\RecordPurchase;
use App\Contracts\Achievements\AchievementProgressCalculator;
use App\Contracts\Payments\CashbackTransferGateway;
use App\Contracts\Payments\TransferRecipientGateway;
use App\Data\Achievements\UserAchievementProgress;
use App\Data\Cashback\CashbackPayoutClaim;
use App\Data\Cashback\CashbackPayoutSupportRequest;
use App\Data\Cashback\RecordedPaystackWebhook;
use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payments\CashbackTransferResult;
use App\Data\Payments\CreatedTransferRecipient;
use App\Data\Payments\PaystackTransferCallback;
use App\Data\Payments\TransferBalance;
use App\Data\Payouts\PayoutAccountRegistrationResult;
use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Data\Purchases\RecordPurchaseInput;
use App\Data\Purchases\RecordPurchaseResult;
use App\Domain\Achievements\AchievementProgressRegistry;
use App\Domain\Achievements\LifetimeSpendProgressCalculator;
use App\Domain\Achievements\PurchaseCountProgressCalculator;
use App\Domain\Money\Money;
use App\Infrastructure\Payments\FakeCashbackTransferGateway;
use App\Infrastructure\Payments\FakeTransferEffectRegistry;
use App\Infrastructure\Payments\FakeTransferRecipientGateway;
use App\Infrastructure\Payments\PaymentProviderRegistry;
use App\Infrastructure\Payments\PaystackCashbackTransferGateway;
use App\Infrastructure\Payments\PaystackClient;
use App\Infrastructure\Payments\PaystackResponse;
use App\Infrastructure\Payments\PaystackTransferRecipientGateway;
use App\Models\Achievement;
use App\Models\AchievementGroup;
use App\Models\Badge;
use App\Models\CashbackReward;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\ProviderWebhookReceipt;
use App\Models\Purchase;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserBadge;
use ReflectionClass;

it('uses final readonly classes for leaf values and stateless actions without freezing eloquent models', function (): void {
    $finalReadonlyClasses = [
        Money::class,
        RecordPurchaseInput::class,
        RecordPurchaseResult::class,
        RecordPurchase::class,
        GetUserAchievementProgress::class,
        UserAchievementProgress::class,
        AchievementProgressRegistry::class,
        PurchaseCountProgressCalculator::class,
        LifetimeSpendProgressCalculator::class,
        EvaluatePurchaseAchievements::class,
        UnlockAchievement::class,
        EvaluateBadges::class,
        UnlockBadge::class,
        CreateCashbackReward::class,
        QueueCashbackPayouts::class,
        QueueCashbackPayout::class,
        ListCashbackRewards::class,
        HandlePaystackWebhook::class,
        ProcessCashbackPayout::class,
        RequestCashbackPayoutSupport::class,
        VerifyPaystackWebhookSignature::class,
        RegisterPayoutAccount::class,
        RegisterPayoutAccountInput::class,
        PayoutAccountRegistrationResult::class,
        CreatedTransferRecipient::class,
        TransferBalance::class,
        CashbackTransferRequest::class,
        CashbackTransferResult::class,
        CashbackPayoutClaim::class,
        CashbackPayoutSupportRequest::class,
        RecordedPaystackWebhook::class,
        PaystackTransferCallback::class,
        FakeCashbackTransferGateway::class,
        FakeTransferEffectRegistry::class,
        FakeTransferRecipientGateway::class,
        PaymentProviderRegistry::class,
        PaystackClient::class,
        PaystackResponse::class,
        PaystackTransferRecipientGateway::class,
        PaystackCashbackTransferGateway::class,
    ];

    foreach ($finalReadonlyClasses as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue();
    }

    $mutableModels = [
        User::class,
        Purchase::class,
        AchievementGroup::class,
        Achievement::class,
        UserAchievement::class,
        Badge::class,
        UserBadge::class,
        CashbackReward::class,
        PayoutAccount::class,
        Payout::class,
        ProviderWebhookReceipt::class,
    ];

    foreach ($mutableModels as $class) {
        expect((new ReflectionClass($class))->isReadOnly())->toBeFalse();
    }

    expect((new ReflectionClass(AchievementProgressCalculator::class))->isInterface())->toBeTrue();
    expect((new ReflectionClass(TransferRecipientGateway::class))->isInterface())->toBeTrue();
    expect((new ReflectionClass(CashbackTransferGateway::class))->isInterface())->toBeTrue();
});

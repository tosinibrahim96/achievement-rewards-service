<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\AchievementMetric;
use App\Enums\CashbackRewardStatus;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Models\Achievement;
use App\Models\AchievementGroup;
use App\Models\Badge;
use App\Models\CashbackReward;
use App\Models\PayoutAccount;
use App\Models\Purchase;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserBadge;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

final class DemoPersonaSeeder extends Seeder
{
    public const string DEMO_PASSWORD = 'password';

    public const string SYSTEM_EMAIL = 'demo.purchase-system@example.test';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $amountMinor = config('rewards.badge_cashback_amount_minor');
        $currencyValue = config('rewards.currency');

        if (! is_int($amountMinor) || $amountMinor <= 0 || ! is_string($currencyValue)) {
            throw new LogicException('Demo rewards require a positive amount and supported currency.');
        }

        $currency = Currency::tryFrom($currencyValue);

        if (! $currency instanceof Currency) {
            throw new LogicException('Demo rewards require a positive amount and supported currency.');
        }

        $password = Hash::make(self::DEMO_PASSWORD);

        DB::transaction(function () use ($amountMinor, $currency, $password): void {
            foreach ($this->customerDefinitions() as $index => $definition) {
                $this->seedCustomer(
                    definition: $definition,
                    personaNumber: $index + 1,
                    password: $password,
                    rewardAmountMinor: $amountMinor,
                    currency: $currency,
                );
            }

            $system = User::query()->firstOrCreate(
                ['email' => self::SYSTEM_EMAIL],
                [
                    'name' => 'Demo Purchase System',
                    'password' => $password,
                    'account_type' => AccountType::System,
                ],
            );

            $this->assertAccountType($system, AccountType::System);
            $this->assertDemoPassword($system);
        });
    }

    /**
     * @param array{
     *     slug: string,
     *     name: string,
     *     email: string,
     *     purchase_amounts_minor: list<positive-int>,
     *     has_payout_account: bool
     * } $definition
     */
    private function seedCustomer(
        array $definition,
        int $personaNumber,
        string $password,
        int $rewardAmountMinor,
        Currency $currency,
    ): void {
        $user = User::query()->firstOrCreate(
            ['email' => $definition['email']],
            [
                'name' => $definition['name'],
                'password' => $password,
                'account_type' => AccountType::Customer,
            ],
        );

        $this->assertAccountType($user, AccountType::Customer);
        $this->assertDemoPassword($user);

        if ($definition['has_payout_account']) {
            $this->seedPayoutAccount($user, $definition['slug'], $personaNumber, $currency);
        }

        $purchases = $this->seedPurchases(
            user: $user,
            slug: $definition['slug'],
            amountsMinor: $definition['purchase_amounts_minor'],
            personaNumber: $personaNumber,
            currency: $currency,
        );
        $userAchievements = $this->seedAchievements($user, $purchases);

        $this->seedBadgesAndRewards(
            user: $user,
            userAchievements: $userAchievements,
            rewardAmountMinor: $rewardAmountMinor,
            currency: $currency,
        );
    }

    private function seedPayoutAccount(
        User $user,
        string $slug,
        int $personaNumber,
        Currency $currency,
    ): void {
        PayoutAccount::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'provider' => PaymentProvider::Fake,
                'provider_recipient_code' => 'RCP_DEMO_'.Str::upper(Str::replace('-', '_', $slug)),
                'bank_code' => '044',
                'bank_name' => 'Demo Bank',
                'account_name' => $user->name,
                'account_last_four' => (string) (9000 + $personaNumber),
                'currency' => $currency,
                'verified_at' => CarbonImmutable::parse('2026-01-01 09:00:00', 'UTC'),
            ],
        );
    }

    /**
     * @param  list<positive-int>  $amountsMinor
     * @return list<Purchase>
     */
    private function seedPurchases(
        User $user,
        string $slug,
        array $amountsMinor,
        int $personaNumber,
        Currency $currency,
    ): array {
        $baseTime = CarbonImmutable::parse('2026-01-01 10:00:00', 'UTC')
            ->addDays($personaNumber - 1);
        $purchases = [];

        foreach ($amountsMinor as $index => $amountMinor) {
            $completedAt = $baseTime->addMinutes($index);
            $purchase = Purchase::query()->firstOrCreate(
                ['external_reference' => sprintf('demo-%s-%02d', $slug, $index + 1)],
                [
                    'user_id' => $user->id,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'completed_at' => $completedAt,
                    'correlation_id' => (string) Str::ulid(),
                ],
            );

            if (
                $purchase->user_id !== $user->id
                || $purchase->amount_minor !== $amountMinor
                || $purchase->currency !== $currency
                || ! $purchase->completed_at->equalTo($completedAt)
            ) {
                throw new LogicException(
                    "The reserved demo purchase reference {$purchase->external_reference} already belongs to different facts.",
                );
            }

            $purchases[] = $purchase;
        }

        return $purchases;
    }

    /**
     * @param  list<Purchase>  $purchases
     * @return list<UserAchievement>
     */
    private function seedAchievements(User $user, array $purchases): array
    {
        /** @var list<array{
         *     achievement: Achievement,
         *     purchase: Purchase,
         *     purchase_index: int,
         *     group_order: int
         * }> $earned
         */
        $earned = [];
        $groups = AchievementGroup::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($groups as $group) {
            $achievements = Achievement::query()
                ->whereBelongsTo($group, 'group')
                ->where('is_active', true)
                ->orderBy('threshold')
                ->orderBy('id')
                ->get();

            foreach ($achievements as $achievement) {
                $purchaseIndex = $this->crossingPurchaseIndex(
                    metric: $group->metric,
                    threshold: $achievement->threshold,
                    purchases: $purchases,
                );

                if ($purchaseIndex === null) {
                    continue;
                }

                $earned[] = [
                    'achievement' => $achievement,
                    'purchase' => $purchases[$purchaseIndex],
                    'purchase_index' => $purchaseIndex,
                    'group_order' => $group->sort_order,
                ];
            }
        }

        usort($earned, static fn (array $left, array $right): int => [
            $left['purchase_index'],
            $left['group_order'],
            $left['achievement']->threshold,
            $left['achievement']->id,
        ] <=> [
            $right['purchase_index'],
            $right['group_order'],
            $right['achievement']->threshold,
            $right['achievement']->id,
        ]);

        $userAchievements = [];

        foreach ($earned as $index => $unlock) {
            $purchase = $unlock['purchase'];
            $userAchievements[] = UserAchievement::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'achievement_id' => $unlock['achievement']->id,
                ],
                [
                    'triggered_by_purchase_id' => $purchase->id,
                    'correlation_id' => $purchase->correlation_id,
                    'unlocked_at' => $purchase->completed_at->addSeconds($index + 1),
                ],
            );
        }

        return $userAchievements;
    }

    /**
     * @param  list<Purchase>  $purchases
     */
    private function crossingPurchaseIndex(
        AchievementMetric $metric,
        int $threshold,
        array $purchases,
    ): ?int {
        $progress = 0;

        foreach ($purchases as $index => $purchase) {
            $progress = match ($metric) {
                AchievementMetric::PurchaseCount => $index + 1,
                AchievementMetric::LifetimeSpend => $progress + $purchase->amount_minor,
            };

            if ($progress >= $threshold) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<UserAchievement>  $userAchievements
     */
    private function seedBadgesAndRewards(
        User $user,
        array $userAchievements,
        int $rewardAmountMinor,
        Currency $currency,
    ): void {
        $badges = Badge::query()
            ->where('is_active', true)
            ->where('required_achievement_count', '<=', count($userAchievements))
            ->orderBy('rank')
            ->orderBy('id')
            ->get();

        foreach ($badges as $badge) {
            $trigger = $userAchievements[$badge->required_achievement_count - 1];
            $userBadge = UserBadge::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'badge_id' => $badge->id,
                ],
                [
                    'triggered_by_user_achievement_id' => $trigger->id,
                    'correlation_id' => $trigger->correlation_id,
                    'unlocked_at' => $trigger->unlocked_at,
                ],
            );

            CashbackReward::query()->firstOrCreate(
                ['user_badge_id' => $userBadge->id],
                [
                    'user_id' => $user->id,
                    'amount_minor' => $rewardAmountMinor,
                    'currency' => $currency,
                    'provider_reference' => 'cashback-'.Str::lower((string) Str::ulid()),
                    'status' => CashbackRewardStatus::AwaitingPayoutAccount,
                    'correlation_id' => $userBadge->correlation_id,
                ],
            );
        }
    }

    private function assertAccountType(User $user, AccountType $expected): void
    {
        if ($user->account_type !== $expected) {
            throw new LogicException(
                "The reserved demo email {$user->email} already belongs to a different account type.",
            );
        }
    }

    private function assertDemoPassword(User $user): void
    {
        if (! Hash::check(self::DEMO_PASSWORD, $user->password)) {
            throw new LogicException(
                "The reserved demo email {$user->email} does not use the documented demo password.",
            );
        }
    }

    /**
     * @return list<array{
     *     slug: string,
     *     name: string,
     *     email: string,
     *     purchase_amounts_minor: list<positive-int>,
     *     has_payout_account: bool
     * }>
     */
    private function customerDefinitions(): array
    {
        return [
            [
                'slug' => 'fresh',
                'name' => 'Demo Fresh Customer',
                'email' => 'demo.fresh@example.test',
                'purchase_amounts_minor' => [],
                'has_payout_account' => false,
            ],
            [
                'slug' => 'one-purchase',
                'name' => 'Demo One Purchase Customer',
                'email' => 'demo.one-purchase@example.test',
                'purchase_amounts_minor' => [100_000],
                'has_payout_account' => false,
            ],
            [
                'slug' => 'two-purchases',
                'name' => 'Demo Two Purchases Customer',
                'email' => 'demo.two-purchases@example.test',
                'purchase_amounts_minor' => [100_000, 100_000],
                'has_payout_account' => false,
            ],
            [
                'slug' => 'intermediate-next',
                'name' => 'Demo Intermediate Next Customer',
                'email' => 'demo.intermediate-next@example.test',
                'purchase_amounts_minor' => array_fill(0, 4, 125_000),
                'has_payout_account' => false,
            ],
            [
                'slug' => 'advanced-next',
                'name' => 'Demo Advanced Next Customer',
                'email' => 'demo.advanced-next@example.test',
                'purchase_amounts_minor' => [...array_fill(0, 8, 500_000), 1_000_000],
                'has_payout_account' => false,
            ],
            [
                'slug' => 'master-next',
                'name' => 'Demo Master Next Customer',
                'email' => 'demo.master-next@example.test',
                'purchase_amounts_minor' => array_fill(0, 24, 500_000),
                'has_payout_account' => false,
            ],
            [
                'slug' => 'complete',
                'name' => 'Demo Complete Customer',
                'email' => 'demo.complete@example.test',
                'purchase_amounts_minor' => array_fill(0, 25, 400_000),
                'has_payout_account' => false,
            ],
            [
                'slug' => 'payout-success',
                'name' => 'Demo Payout Success Customer',
                'email' => 'demo.payout-success@example.test',
                'purchase_amounts_minor' => [],
                'has_payout_account' => true,
            ],
            [
                'slug' => 'payout-insufficient',
                'name' => 'Demo Payout Insufficient Customer',
                'email' => 'demo.payout-insufficient@example.test',
                'purchase_amounts_minor' => [],
                'has_payout_account' => true,
            ],
        ];
    }
}

<?php

declare(strict_types=1);

use App\Actions\Achievements\EvaluatePurchaseAchievements;
use App\Actions\Badges\EvaluateBadges;
use App\Enums\AccountType;
use App\Enums\CashbackRewardStatus;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PayoutStatus;
use App\Enums\TokenAbility;
use App\Events\AchievementUnlocked;
use App\Events\BadgeUnlocked;
use App\Events\PayoutAccountVerified;
use App\Events\PurchaseCompleted;
use App\Models\CashbackReward;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\Purchase;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserBadge;
use Carbon\CarbonImmutable;
use Database\Seeders\AchievementCatalogueSeeder;
use Database\Seeders\BadgeCatalogueSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoPersonaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

it('seeds truthful customer snapshots without dispatching demo side effects', function (): void {
    Queue::fake();
    Event::fake([
        PurchaseCompleted::class,
        AchievementUnlocked::class,
        BadgeUnlocked::class,
        PayoutAccountVerified::class,
    ]);
    Notification::fake();

    $this->seed();

    $expected = [
        'demo.fresh@example.test' => [0, 0, [], [], 0, false],
        'demo.one-purchase@example.test' => [1, 100_000, ['first-purchase'], ['beginner'], 1, false],
        'demo.two-purchases@example.test' => [2, 200_000, ['first-purchase'], ['beginner'], 1, false],
        'demo.intermediate-next@example.test' => [
            4,
            500_000,
            ['first-purchase', 'five-thousand-spent', 'three-purchases'],
            ['beginner'],
            1,
            false,
        ],
        'demo.advanced-next@example.test' => [
            9,
            5_000_000,
            [
                'fifty-thousand-spent',
                'first-purchase',
                'five-purchases',
                'five-thousand-spent',
                'ten-thousand-spent',
                'three-purchases',
                'twenty-five-thousand-spent',
            ],
            ['beginner', 'intermediate'],
            2,
            false,
        ],
        'demo.master-next@example.test' => [
            24,
            12_000_000,
            [
                'fifty-thousand-spent',
                'first-purchase',
                'five-purchases',
                'five-thousand-spent',
                'one-hundred-thousand-spent',
                'ten-purchases',
                'ten-thousand-spent',
                'three-purchases',
                'twenty-five-thousand-spent',
            ],
            ['advanced', 'beginner', 'intermediate'],
            3,
            false,
        ],
        'demo.complete@example.test' => [
            25,
            10_000_000,
            [
                'fifty-thousand-spent',
                'first-purchase',
                'five-purchases',
                'five-thousand-spent',
                'one-hundred-thousand-spent',
                'ten-purchases',
                'ten-thousand-spent',
                'three-purchases',
                'twenty-five-purchases',
                'twenty-five-thousand-spent',
            ],
            ['advanced', 'beginner', 'intermediate', 'master'],
            4,
            false,
        ],
        'demo.payout-success@example.test' => [0, 0, [], [], 0, true],
        'demo.payout-insufficient@example.test' => [0, 0, [], [], 0, true],
    ];

    foreach ($expected as $email => [$purchaseCount, $spend, $achievements, $badges, $rewardCount, $hasAccount]) {
        $user = User::query()->where('email', $email)->sole();
        $actualAchievements = $user->userAchievements()
            ->with('achievement')
            ->get()
            ->pluck('achievement.code')
            ->sort()
            ->values()
            ->all();
        $actualBadges = $user->userBadges()
            ->with('badge')
            ->get()
            ->pluck('badge.code')
            ->sort()
            ->values()
            ->all();

        expect($user->account_type)->toBe(AccountType::Customer)
            ->and(Hash::check(DemoPersonaSeeder::DEMO_PASSWORD, $user->password))->toBeTrue()
            ->and($user->purchases()->count())->toBe($purchaseCount)
            ->and((int) $user->purchases()->sum('amount_minor'))->toBe($spend)
            ->and($actualAchievements)->toBe($achievements)
            ->and($actualBadges)->toBe($badges)
            ->and($user->cashbackRewards()->count())->toBe($rewardCount)
            ->and($user->cashbackRewards()->get()->every(
                static fn (CashbackReward $reward): bool => $reward->status === CashbackRewardStatus::AwaitingPayoutAccount,
            ))->toBeTrue()
            ->and($user->payoutAccount()->exists())->toBe($hasAccount);
    }

    $payoutAccounts = PayoutAccount::query()->get();

    expect($payoutAccounts)->toHaveCount(2)
        ->and($payoutAccounts->every(
            static fn (PayoutAccount $account): bool => $account->provider === PaymentProvider::Fake,
        ))->toBeTrue()
        ->and($payoutAccounts->every(
            static fn (PayoutAccount $account): bool => str_starts_with(
                $account->provider_recipient_code,
                'RCP_DEMO_',
            ),
        ))->toBeTrue()
        ->and(Payout::query()->count())->toBe(0);

    Queue::assertNothingPushed();
    Event::assertNothingDispatched();
    Notification::assertNothingSent();
});

it('keeps the complete database seeder free of predictable identities outside local and testing', function (): void {
    app()->detectEnvironment(static fn (): string => 'production');

    app(DatabaseSeeder::class)->run();

    expect(User::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

it('seeds one system identity that logs in and records a purchase through the API', function (): void {
    $this->seed();

    $system = User::query()->where('email', DemoPersonaSeeder::SYSTEM_EMAIL)->sole();
    $customer = User::query()->where('email', 'demo.fresh@example.test')->sole();

    expect($system->account_type)->toBe(AccountType::System)
        ->and(Hash::check(DemoPersonaSeeder::DEMO_PASSWORD, $system->password))->toBeTrue()
        ->and($system->tokens()->count())->toBe(0)
        ->and(User::query()->where('account_type', AccountType::System)->count())->toBe(1);

    $this->postJson('/api/auth/login', [
        'email' => DemoPersonaSeeder::SYSTEM_EMAIL,
        'password' => DemoPersonaSeeder::DEMO_PASSWORD,
    ])->assertUnauthorized()->assertJson([
        'code' => 'invalid_credentials',
    ]);

    $login = $this->postJson('/api/auth/system/login', [
        'email' => DemoPersonaSeeder::SYSTEM_EMAIL,
        'password' => DemoPersonaSeeder::DEMO_PASSWORD,
        'device_name' => 'Seeder API proof',
    ])
        ->assertOk()
        ->assertJsonPath('user.id', $system->id)
        ->assertJsonPath('user.account_type', AccountType::System->value)
        ->assertJsonPath('abilities', TokenAbility::systemValues());
    $token = $login->json('token');

    expect($token)->toBeString()
        ->and($system->tokens()->sole()->abilities)->toBe(TokenAbility::systemValues());

    Event::fake([PurchaseCompleted::class]);

    $this->withToken($token)
        ->postJson('/api/internal/purchases', [
            'user_id' => $customer->id,
            'external_reference' => 'README-SYSTEM-LOGIN-PROOF-001',
            'amount_minor' => 100_000,
            'currency' => Currency::Ngn->value,
            'completed_at' => '2026-08-24T18:00:00Z',
        ])
        ->assertCreated()
        ->assertJsonPath('purchase.user_id', $customer->id)
        ->assertJsonPath('was_duplicate', false);

    Event::assertDispatchedTimes(PurchaseCompleted::class, 1);
});

it('refuses a reserved demo identity with a different password', function (): void {
    User::factory()->system()->create([
        'email' => DemoPersonaSeeder::SYSTEM_EMAIL,
        'password' => 'different-password',
    ]);

    expect(static fn (): mixed => app(DemoPersonaSeeder::class)->run())
        ->toThrow(LogicException::class, 'does not use the documented demo password');

    expect(User::query()->count())->toBe(1);
});

it('can run again without duplicating or rewinding demo state', function (): void {
    $this->seed();

    $paidAt = CarbonImmutable::parse('2026-08-24T16:00:00Z');
    $reward = CashbackReward::query()
        ->whereHas('user', static fn ($query) => $query->where('email', 'demo.one-purchase@example.test'))
        ->sole();
    $reward->update([
        'status' => CashbackRewardStatus::Paid,
        'paid_at' => $paidAt,
    ]);
    $rewardAccount = PayoutAccount::factory()->for($reward->user)->create();
    $payout = Payout::factory()->create([
        'cashback_reward_id' => $reward->id,
        'payout_account_id' => $rewardAccount->id,
        'status' => PayoutStatus::Succeeded,
        'provider_transfer_code' => 'TRF_RESEED_PROOF',
        'provider_http_status' => 200,
        'succeeded_at' => $paidAt,
        'first_result_at' => $paidAt,
    ]);
    $seededPayoutAccount = PayoutAccount::query()
        ->whereHas('user', static fn ($query) => $query->where('email', 'demo.payout-success@example.test'))
        ->sole();
    $seededPayoutAccount->update(['bank_name' => 'Changed During Demo']);

    $before = [
        User::query()->count(),
        Purchase::query()->count(),
        UserAchievement::query()->count(),
        UserBadge::query()->count(),
        CashbackReward::query()->count(),
        PayoutAccount::query()->count(),
        Payout::query()->count(),
    ];

    $this->seed();

    expect([
        User::query()->count(),
        Purchase::query()->count(),
        UserAchievement::query()->count(),
        UserBadge::query()->count(),
        CashbackReward::query()->count(),
        PayoutAccount::query()->count(),
        Payout::query()->count(),
    ])->toBe($before)
        ->and($reward->fresh()?->status)->toBe(CashbackRewardStatus::Paid)
        ->and($reward->fresh()?->paid_at?->equalTo($paidAt))->toBeTrue()
        ->and($payout->fresh()?->status)->toBe(PayoutStatus::Succeeded)
        ->and($payout->fresh()?->provider_transfer_code)->toBe('TRF_RESEED_PROOF')
        ->and($seededPayoutAccount->fresh()?->bank_name)->toBe('Changed During Demo');
});

it('refuses a reserved demo email owned by a different account type', function (): void {
    User::factory()->create([
        'email' => 'demo.fresh@example.test',
        'account_type' => AccountType::System,
    ]);

    expect(static fn (): mixed => app(DemoPersonaSeeder::class)->run())
        ->toThrow(LogicException::class, 'reserved demo email demo.fresh@example.test');

    expect(User::query()->count())->toBe(1);
});

it('refuses a reserved demo purchase reference attached to different facts', function (): void {
    $this->seed([
        AchievementCatalogueSeeder::class,
        BadgeCatalogueSeeder::class,
    ]);

    $owner = User::factory()->create();
    Purchase::factory()->for($owner)->create([
        'external_reference' => 'demo-one-purchase-01',
        'amount_minor' => 999_999,
        'currency' => Currency::Ngn,
        'completed_at' => '2026-01-01T10:00:00Z',
    ]);

    expect(static fn (): mixed => app(DemoPersonaSeeder::class)->run())
        ->toThrow(LogicException::class, 'reserved demo purchase reference demo-one-purchase-01');

    expect(User::query()->count())->toBe(1)
        ->and(Purchase::query()->count())->toBe(1);
});

it('places each progression persona exactly one purchase before its named result', function (): void {
    $this->seed();
    Event::fake([AchievementUnlocked::class, BadgeUnlocked::class]);

    $scenarios = [
        'demo.fresh@example.test' => [100_000, ['first-purchase'], ['beginner']],
        'demo.one-purchase@example.test' => [400_000, ['five-thousand-spent'], []],
        'demo.two-purchases@example.test' => [100_000, ['three-purchases'], []],
        'demo.intermediate-next@example.test' => [100_000, ['five-purchases'], ['intermediate']],
        'demo.advanced-next@example.test' => [100_000, ['ten-purchases'], ['advanced']],
        'demo.master-next@example.test' => [100_000, ['twenty-five-purchases'], ['master']],
        'demo.complete@example.test' => [100_000, [], []],
    ];

    foreach ($scenarios as $email => [$amountMinor, $expectedAchievements, $expectedBadges]) {
        $user = User::query()->where('email', $email)->sole();
        $achievementIdsBefore = $user->userAchievements()->pluck('id');
        $badgeIdsBefore = $user->userBadges()->pluck('id');
        $purchase = Purchase::query()->create([
            'user_id' => $user->id,
            'external_reference' => 'next-'.Str::before($email, '@'),
            'amount_minor' => $amountMinor,
            'currency' => Currency::Ngn,
            'completed_at' => '2026-02-01T10:00:00Z',
            'correlation_id' => (string) Str::ulid(),
        ]);

        app(EvaluatePurchaseAchievements::class)->handle($purchase);
        app(EvaluateBadges::class)->handle($user);

        $newAchievements = $user->userAchievements()
            ->whereNotIn('id', $achievementIdsBefore)
            ->with('achievement')
            ->get()
            ->pluck('achievement.code')
            ->sort()
            ->values()
            ->all();
        $newBadges = $user->userBadges()
            ->whereNotIn('id', $badgeIdsBefore)
            ->with('badge')
            ->get()
            ->pluck('badge.code')
            ->sort()
            ->values()
            ->all();

        expect($newAchievements)->toBe($expectedAchievements)
            ->and($newBadges)->toBe($expectedBadges);
    }
});

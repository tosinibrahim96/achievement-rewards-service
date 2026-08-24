<?php

declare(strict_types=1);

use App\Actions\Badges\EvaluateBadges;
use App\Actions\Cashback\CreateCashbackReward;
use App\Enums\CashbackRewardStatus;
use App\Enums\Currency;
use App\Events\BadgeUnlocked;
use App\Models\Badge;
use App\Models\CashbackReward;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Models\UserBadge;
use App\Notifications\CashbackRewardNeedsPayoutAccount;
use Database\Seeders\AchievementCatalogueSeeder;
use Database\Seeders\BadgeCatalogueSeeder;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mockery;
use RuntimeException;
use Tests\Support\BadgeTestData;
use Throwable;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->seed([AchievementCatalogueSeeder::class, BadgeCatalogueSeeder::class]);
    config()->set('queue.default', 'sync');
    config()->set('mail.default', 'array');
});

function userBadgeForCashbackRewardNotificationTest(
    User $user,
    string $badgeName = 'Beginner',
): UserBadge {
    $badge = Badge::factory()->create(['name' => $badgeName]);

    return UserBadge::factory()
        ->for($user)
        ->for($badge)
        ->create();
}

it('notifies the registered customer when a new badge reward needs a payout account', function (): void {
    Event::fake([BadgeUnlocked::class]);
    Notification::fake()->serializeAndRestore();
    $user = User::factory()->unverified()->create([
        'email' => 'customer@example.test',
    ]);
    BadgeTestData::giveAchievements($user, 1);

    app(EvaluateBadges::class)->handle($user);

    $reward = CashbackReward::query()->whereBelongsTo($user)->firstOrFail();

    expect($reward->status)->toBe(CashbackRewardStatus::AwaitingPayoutAccount)
        ->and($user->email_verified_at)->toBeNull();
    Notification::assertSentTo(
        $user,
        CashbackRewardNeedsPayoutAccount::class,
        function (
            CashbackRewardNeedsPayoutAccount $notification,
            array $channels,
            User $notifiable,
        ): bool {
            $mail = $notification->toMail($notifiable);

            return $channels === ['mail']
                && $notification instanceof ShouldQueueAfterCommit
                && $notifiable->routeNotificationFor('mail', $notification) === 'customer@example.test'
                && $notification->badgeName === 'Beginner'
                && $notification->amountMinor === 30_000
                && $notification->currency === Currency::Ngn
                && $mail->subject === 'Add a payout account for your cashback reward'
                && $mail->introLines === [
                    'You earned a NGN 300.00 cashback reward for the Beginner badge.',
                    'Add a payout account to receive this reward.',
                ]
                && $mail->actionText === null
                && $mail->actionUrl === null;
        },
    );
    Notification::assertSentToTimes($user, CashbackRewardNeedsPayoutAccount::class, 1);
    Notification::assertCount(1);
});

it('does not notify a customer whose verified payout account already exists', function (): void {
    Event::fake([BadgeUnlocked::class]);
    Notification::fake();
    $user = User::factory()->create();
    PayoutAccount::factory()->for($user)->create();
    BadgeTestData::giveAchievements($user, 1);

    app(EvaluateBadges::class)->handle($user);

    expect(CashbackReward::query()->whereBelongsTo($user)->value('status'))
        ->toBe(CashbackRewardStatus::ReadyForPayout);
    Notification::assertNothingSent();
});

it('does not request a second notification when reward creation is replayed', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $userBadge = userBadgeForCashbackRewardNotificationTest($user);

    $firstReward = DB::transaction(
        fn (): CashbackReward => app(CreateCashbackReward::class)->handle($userBadge),
    );
    $replayedReward = DB::transaction(
        fn (): CashbackReward => app(CreateCashbackReward::class)->handle($userBadge),
    );

    expect($replayedReward->is($firstReward))->toBeTrue()
        ->and(CashbackReward::query()->where('user_badge_id', $userBadge->id)->count())->toBe(1);
    Notification::assertSentToTimes($user, CashbackRewardNeedsPayoutAccount::class, 1);
});

it('sends the queued notification only after the reward transaction commits', function (): void {
    Event::fake([NotificationSent::class]);
    $user = User::factory()->create();
    $userBadge = userBadgeForCashbackRewardNotificationTest($user);

    DB::beginTransaction();

    try {
        app(CreateCashbackReward::class)->handle($userBadge);
        Event::assertNotDispatched(NotificationSent::class);
        DB::commit();
    } catch (Throwable $exception) {
        if (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }

        throw $exception;
    }

    Event::assertDispatched(
        NotificationSent::class,
        fn (NotificationSent $event): bool => $event->notifiable->is($user)
            && $event->notification instanceof CashbackRewardNeedsPayoutAccount
            && $event->channel === 'mail',
    );
});

it('discards the queued notification when the reward transaction rolls back', function (): void {
    Event::fake([NotificationSent::class]);
    $user = User::factory()->create();
    $userBadge = userBadgeForCashbackRewardNotificationTest($user);

    DB::beginTransaction();

    try {
        app(CreateCashbackReward::class)->handle($userBadge);
        Event::assertNotDispatched(NotificationSent::class);
    } finally {
        DB::rollBack();
    }

    expect(CashbackReward::query()->where('user_badge_id', $userBadge->id)->exists())->toBeFalse();
    Event::assertNotDispatched(NotificationSent::class);
});

it('keeps the committed reward and later callbacks when notification queueing fails', function (): void {
    Log::spy();
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('send')->once()->andThrow(new RuntimeException('queue unavailable'));
    app()->instance(Dispatcher::class, $dispatcher);
    $user = User::factory()->create();
    $userBadge = userBadgeForCashbackRewardNotificationTest($user);
    $laterCallbackRan = false;

    $reward = DB::transaction(function () use ($userBadge, &$laterCallbackRan): CashbackReward {
        $reward = app(CreateCashbackReward::class)->handle($userBadge);

        DB::afterCommit(function () use (&$laterCallbackRan): void {
            $laterCallbackRan = true;
        });

        return $reward;
    });

    expect($reward->exists)->toBeTrue()
        ->and($reward->status)->toBe(CashbackRewardStatus::AwaitingPayoutAccount)
        ->and(CashbackReward::query()->whereKey($reward->id)->exists())->toBeTrue()
        ->and($laterCallbackRan)->toBeTrue();
});

it('formats the reward amount and does not add an action link', function (): void {
    $user = User::factory()->create();
    $notification = new CashbackRewardNeedsPayoutAccount(
        badgeName: 'Intermediate',
        amountMinor: 30_005,
        currency: Currency::Ngn,
    );
    $mail = $notification->toMail($user);

    expect($mail->introLines)->toBe([
        'You earned a NGN 300.05 cashback reward for the Intermediate badge.',
        'Add a payout account to receive this reward.',
    ])
        ->and($mail->actionText)->toBeNull()
        ->and($mail->actionUrl)->toBeNull();
});

<?php

declare(strict_types=1);

use App\Actions\Achievements\EvaluatePurchaseAchievements;
use App\Actions\Purchases\RecordPurchase;
use App\Data\Purchases\RecordPurchaseInput;
use App\Domain\Money\Money;
use App\Enums\Currency;
use App\Events\PurchaseCompleted;
use App\Listeners\EvaluatePurchaseAchievementsListener;
use App\Models\Purchase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AchievementCatalogueSeeder;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ConcurrentRunner;

uses(DatabaseMigrations::class);

/** @return Closure(): void */
function recordPurchaseConcurrently(
    int $userId,
    string $externalReference,
    int $amountMinor = 100,
): Closure {
    return static function () use ($userId, $externalReference, $amountMinor): void {
        app(RecordPurchase::class)->handle(new RecordPurchaseInput(
            userId: $userId,
            externalReference: $externalReference,
            amount: new Money($amountMinor, Currency::Ngn),
            completedAt: CarbonImmutable::parse('2026-08-21T14:30:00Z'),
        ));
    };
}

it('creates one purchase for identical simultaneous deliveries', function (): void {
    $this->seed(AchievementCatalogueSeeder::class);
    $user = User::factory()->create();

    (new ConcurrentRunner)->run([
        recordPurchaseConcurrently($user->id, 'ORDER-CONCURRENT-SAME'),
        recordPurchaseConcurrently($user->id, 'ORDER-CONCURRENT-SAME'),
    ]);

    expect(Purchase::query()->where('external_reference', 'ORDER-CONCURRENT-SAME')->count())->toBe(1)
        ->and($user->userAchievements()->count())->toBe(1)
        ->and($user->userAchievements()->firstOrFail()->achievement->code)->toBe('first-purchase');
});

it('creates each crossed achievement once for simultaneous qualifying purchases', function (): void {
    $this->seed(AchievementCatalogueSeeder::class);
    $user = User::factory()->create();
    Purchase::factory()->count(4)->for($user)->create(['amount_minor' => 100]);

    (new ConcurrentRunner)->run([
        recordPurchaseConcurrently($user->id, 'ORDER-CONCURRENT-A'),
        recordPurchaseConcurrently($user->id, 'ORDER-CONCURRENT-B'),
    ]);

    $unlockedCodes = $user->userAchievements()
        ->with('achievement')
        ->get()
        ->pluck('achievement.code')
        ->sort()
        ->values()
        ->all();

    expect(Purchase::query()->where('user_id', $user->id)->count())->toBe(6)
        ->and($unlockedCodes)->toBe(['first-purchase', 'five-purchases', 'three-purchases'])
        ->and($user->userAchievements()->count())
        ->toBe($user->userAchievements()->distinct('achievement_id')->count('achievement_id'));
});

it('keeps overlapping listener work idempotent', function (): void {
    $this->seed(AchievementCatalogueSeeder::class);
    $user = User::factory()->create();
    $purchase = Purchase::factory()->for($user)->create(['amount_minor' => 100]);

    (new ConcurrentRunner)->run([
        static fn () => app(EvaluatePurchaseAchievements::class)->handle($purchase),
        static fn () => app(EvaluatePurchaseAchievements::class)->handle($purchase),
    ]);

    expect($user->userAchievements()->count())->toBe(1)
        ->and($user->userAchievements()->firstOrFail()->achievement->code)->toBe('first-purchase');
});

it('releases overlapping queue work for a retry using the shared redis lock', function (): void {
    $this->seed(AchievementCatalogueSeeder::class);
    $user = User::factory()->create();
    $purchase = Purchase::factory()->for($user)->create(['amount_minor' => 100]);
    $event = new PurchaseCompleted($purchase);
    $listener = app(EvaluatePurchaseAchievementsListener::class);
    $middleware = $listener->middleware($event)[0];

    expect($listener->tries)->toBe(10)
        ->and($middleware)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware->releaseAfter)->toBe(1)
        ->and($middleware->expiresAfter)->toBe(60)
        ->and($middleware->getLockKey(new stdClass))->toBe("achievement-progression:user:{$user->id}");

    $redisCache = Cache::store('redis');
    app()->instance(CacheRepository::class, $redisCache);
    $heldLock = $redisCache->lock($middleware->getLockKey(new stdClass), 60);

    expect($heldLock->get())->toBeTrue();

    $job = new class
    {
        public ?int $releasedAfter = null;

        public function release(int $delay): void
        {
            $this->releasedAfter = $delay;
        }
    };
    $handled = false;

    try {
        $middleware->handle($job, function () use (&$handled): void {
            $handled = true;
        });

        expect($handled)->toBeFalse()
            ->and($job->releasedAfter)->toBe(1);
    } finally {
        $heldLock->release();
    }

    $retriedJob = clone $job;
    $retriedJob->releasedAfter = null;
    $middleware->handle($retriedJob, function () use ($listener, $event, &$handled): void {
        $listener->handle($event);
        $handled = true;
    });

    expect($handled)->toBeTrue()
        ->and($retriedJob->releasedAfter)->toBeNull()
        ->and($user->userAchievements()->count())->toBe(1);
});

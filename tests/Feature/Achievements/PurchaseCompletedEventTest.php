<?php

declare(strict_types=1);

use App\Actions\Purchases\RecordPurchase;
use App\Data\Purchases\RecordPurchaseInput;
use App\Domain\Money\Money;
use App\Enums\Currency;
use App\Events\PurchaseCompleted;
use App\Listeners\EvaluatePurchaseAchievementsListener;
use App\Models\Purchase;
use App\Models\User;
use App\Models\UserAchievement;
use Carbon\CarbonImmutable;
use Database\Seeders\AchievementCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;

uses(DatabaseMigrations::class);

function eventTestInput(User $user, string $reference): RecordPurchaseInput
{
    return new RecordPurchaseInput(
        userId: $user->id,
        externalReference: $reference,
        amount: new Money(100, Currency::Ngn),
        completedAt: CarbonImmutable::parse('2026-08-21T14:30:00Z'),
    );
}

it('dispatches PurchaseCompleted and logs the purchase only after the outer transaction commits', function (): void {
    Event::fake([PurchaseCompleted::class]);
    Log::spy();
    $user = User::factory()->create();

    DB::transaction(function () use ($user): void {
        app(RecordPurchase::class)->handle(eventTestInput($user, 'ORDER-AFTER-COMMIT'));

        Event::assertNotDispatched(PurchaseCompleted::class);
        Log::shouldNotHaveReceived('info', ['purchase.processed', Mockery::type('array')]);
    });

    Event::assertDispatchedTimes(PurchaseCompleted::class, 1);
    Log::shouldHaveReceived('info')
        ->once()
        ->with('purchase.processed', Mockery::type('array'));
});

it('rolls back the purchase without dispatching or logging', function (): void {
    Event::fake([PurchaseCompleted::class]);
    Log::spy();
    $this->seed(AchievementCatalogueSeeder::class);
    $user = User::factory()->create();

    try {
        DB::transaction(function () use ($user): never {
            app(RecordPurchase::class)->handle(eventTestInput($user, 'ORDER-ROLLBACK'));

            throw new RuntimeException('Force rollback.');
        });
    } catch (RuntimeException) {
        // The rollback is the behaviour under test.
    }

    expect(Purchase::query()->count())->toBe(0)
        ->and(UserAchievement::query()->count())->toBe(0);
    Event::assertNotDispatched(PurchaseCompleted::class);
    Log::shouldNotHaveReceived('info', ['purchase.processed', Mockery::type('array')]);
});

it('keeps the purchase and dispatches PurchaseCompleted when its log fails', function (): void {
    Event::fake([PurchaseCompleted::class]);
    Log::spy();
    Log::shouldReceive('info')
        ->once()
        ->with('purchase.processed', Mockery::type('array'))
        ->andThrow(new RuntimeException('purchase log unavailable'));
    $user = User::factory()->create();

    $purchaseResult = app(RecordPurchase::class)->handle(eventTestInput($user, 'ORDER-LOG-FAILURE'));

    expect($purchaseResult->wasDuplicate)->toBeFalse()
        ->and(Purchase::query()->whereKey($purchaseResult->purchase->id)->exists())->toBeTrue();
    Event::assertDispatchedTimes(PurchaseCompleted::class, 1);
});

it('discovers the queued achievement progression listener', function (): void {
    Event::fake();
    Event::assertListening(PurchaseCompleted::class, EvaluatePurchaseAchievementsListener::class);

    Artisan::call('event:list');

    expect(Artisan::output())
        ->toContain(PurchaseCompleted::class)
        ->toContain(EvaluatePurchaseAchievementsListener::class);
});

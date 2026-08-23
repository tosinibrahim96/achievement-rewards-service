<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Achievements\AchievementProgressCalculator;
use App\Contracts\Payments\CashbackTransferGateway;
use App\Contracts\Payments\TransferRecipientGateway;
use App\Domain\Achievements\LifetimeSpendProgressCalculator;
use App\Domain\Achievements\PurchaseCountProgressCalculator;
use App\Infrastructure\Payments\FakeCashbackTransferGateway;
use App\Infrastructure\Payments\FakeTransferRecipientGateway;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->tag(
            [PurchaseCountProgressCalculator::class, LifetimeSpendProgressCalculator::class],
            AchievementProgressCalculator::class,
        );

        $this->app->tag(
            [FakeTransferRecipientGateway::class],
            TransferRecipientGateway::class,
        );

        $this->app->tag(
            [FakeCashbackTransferGateway::class],
            CashbackTransferGateway::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        RateLimiter::for('login', static function (Request $request): Limit {
            $email = $request->input('email');
            $normalizedEmail = is_string($email) ? Str::lower(trim($email)) : 'unknown';

            return Limit::perMinute(5)->by($normalizedEmail.'|'.($request->ip() ?? 'unknown'));
        });

        RateLimiter::for(
            'registration',
            static fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip() ?? 'unknown'),
        );

        RateLimiter::for('purchase-ingestion', static function (Request $request): Limit {
            $actor = $request->user();
            $key = $actor instanceof User ? "system:{$actor->id}" : ($request->ip() ?? 'unknown');

            return Limit::perMinute(120)->by($key);
        });

        RateLimiter::for('payout-account', static function (Request $request): Limit {
            $actor = $request->user('sanctum');
            $key = $actor instanceof User ? "customer:{$actor->id}" : ($request->ip() ?? 'unknown');

            return Limit::perMinute(5)->by($key);
        });
    }
}

<?php

declare(strict_types=1);

use App\Actions\Payouts\RegisterPayoutAccount;
use App\Contracts\Payments\TransferRecipientGateway;
use App\Data\Payments\CreatedTransferRecipient;
use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\TokenAbility;
use App\Infrastructure\Payments\PaymentProviderRegistry;
use App\Models\PayoutAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use SensitiveParameter;
use Tests\Support\ConcurrentRunner;

uses(DatabaseMigrations::class);

final readonly class RedisObservedTransferRecipientGateway implements TransferRecipientGateway
{
    public function __construct(private string $namespace) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Fake;
    }

    public function createRecipient(
        #[SensitiveParameter] RegisterPayoutAccountInput $input,
    ): CreatedTransferRecipient {
        $redis = Redis::connection('default');
        $activeKey = "{$this->namespace}:active";
        $callsKey = "{$this->namespace}:calls";
        $overlapKey = "{$this->namespace}:overlap";
        $active = (int) $redis->command('incr', [$activeKey]);
        $redis->command('incr', [$callsKey]);

        try {
            $deadline = microtime(true) + 0.5;

            while ($active === 1 && microtime(true) < $deadline) {
                usleep(10_000);
                $active = (int) $redis->command('get', [$activeKey]);
            }

            if ($active > 1) {
                $redis->command('set', [$overlapKey, '1']);
            }

            return new CreatedTransferRecipient(
                provider: PaymentProvider::Fake,
                recipientCode: 'RCP_OBSERVED_'.hash('sha256', $input->bankCode.'|'.$input->accountNumber),
                accountName: 'Observed Customer',
                bankName: 'Observed Bank',
                bankCode: $input->bankCode,
                accountLastFour: substr($input->accountNumber, -4),
                currency: Currency::Ngn,
            );
        } finally {
            $redis->command('decr', [$activeKey]);
        }
    }
}

/** @return list<string> */
function observedRecipientKeys(string $namespace): array
{
    return [
        "{$namespace}:active",
        "{$namespace}:calls",
        "{$namespace}:overlap",
    ];
}

beforeEach(function (): void {
    config()->set('app.key', 'base64:payout-account-concurrency-key');
    config()->set('cache.default', 'redis');
    config()->set('payments.default', PaymentProvider::Fake->value);
    config()->set('payments.fake.payout_account_scenario', 'success');
});

it('fails safely when the per-user lock cannot be acquired', function (): void {
    $user = User::factory()->create();
    $lockKey = "payout-account:user:{$user->id}";
    $observationNamespace = 'payout-account-provider:'.Str::lower((string) Str::ulid());
    $redis = Redis::connection('default');
    $observationKeys = observedRecipientKeys($observationNamespace);
    $redis->command('del', $observationKeys);
    app()->instance(
        PaymentProviderRegistry::class,
        new PaymentProviderRegistry(
            recipientGateways: [new RedisObservedTransferRecipientGateway($observationNamespace)],
            transferGateways: [],
            defaultProvider: PaymentProvider::Fake->value,
        ),
    );
    $heldLock = Cache::store('redis')->lock($lockKey, 30);
    $heldLock->forceRelease();

    expect($heldLock->get())->toBeTrue();
    config()->set('payments.payout_account_lock.wait_seconds', 0);

    $token = $user->createToken('held-payout-lock', [TokenAbility::PayoutAccountsWrite->value])->plainTextToken;

    try {
        $this->withToken($token)
            ->putJson('/api/me/payout-account', [
                'account_number' => '0000001234',
                'bank_code' => '057',
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'payout_account_busy');
    } finally {
        $heldLock->release();
        $providerCalls = (int) ($redis->command('get', ["{$observationNamespace}:calls"]) ?? 0);
        $redis->command('del', $observationKeys);
    }

    expect(PayoutAccount::query()->count())->toBe(0)
        ->and($providerCalls)->toBe(0);
});

it('serializes provider work and competing replacements into one coherent account', function (): void {
    $user = User::factory()->create();
    $lockKey = "payout-account:user:{$user->id}";
    $observationNamespace = 'payout-account-provider:'.Str::lower((string) Str::ulid());
    $redis = Redis::connection('default');
    $observationKeys = observedRecipientKeys($observationNamespace);
    $redis->command('del', $observationKeys);
    app()->instance(
        PaymentProviderRegistry::class,
        new PaymentProviderRegistry(
            recipientGateways: [new RedisObservedTransferRecipientGateway($observationNamespace)],
            transferGateways: [],
            defaultProvider: PaymentProvider::Fake->value,
        ),
    );
    Cache::store('redis')->lock($lockKey)->forceRelease();

    try {
        (new ConcurrentRunner)->run([
            static fn () => app(RegisterPayoutAccount::class)->handle(
                User::query()->findOrFail($user->id),
                new RegisterPayoutAccountInput('0000001111', '057'),
            ),
            static fn () => app(RegisterPayoutAccount::class)->handle(
                User::query()->findOrFail($user->id),
                new RegisterPayoutAccountInput('0000002222', '058'),
            ),
        ]);

        $providerCalls = (int) ($redis->command('get', ["{$observationNamespace}:calls"]) ?? 0);
        $activeProviderCalls = (int) ($redis->command('get', ["{$observationNamespace}:active"]) ?? 0);
        $providerOverlap = $redis->command('get', ["{$observationNamespace}:overlap"]);
    } finally {
        $redis->command('del', $observationKeys);
    }

    $payoutAccount = PayoutAccount::query()->whereBelongsTo($user)->sole();
    $coherentDestination = [$payoutAccount->bank_code, $payoutAccount->account_last_four];

    expect(PayoutAccount::query()->whereBelongsTo($user)->count())->toBe(1)
        ->and($providerCalls)->toBe(2)
        ->and($activeProviderCalls)->toBe(0)
        ->and($providerOverlap)->toBeFalse()
        ->and($coherentDestination)->toBeIn([
            ['057', '1111'],
            ['058', '2222'],
        ]);
});

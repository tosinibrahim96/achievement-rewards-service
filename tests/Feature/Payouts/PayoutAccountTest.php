<?php

declare(strict_types=1);

use App\Actions\Payouts\RegisterPayoutAccount;
use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PaymentProviderFailure;
use App\Events\PayoutAccountVerified;
use App\Exceptions\Payments\PaymentProviderException;
use App\Exceptions\Payouts\PayoutAccountConflictException;
use App\Infrastructure\Payments\FakeTransferRecipientGateway;
use App\Models\PayoutAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use LogicException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('app.key', 'base64:payout-account-test-key');
    config()->set('payments.default', PaymentProvider::Fake->value);
    config()->set('payments.fake.payout_account_scenario', 'success');
});

it('creates one verified account without retaining the full account number', function (): void {
    Event::fake([PayoutAccountVerified::class]);
    $user = User::factory()->create();
    $accountNumber = '0000001234';

    $result = app(RegisterPayoutAccount::class)->handle(
        $user,
        new RegisterPayoutAccountInput($accountNumber, '057'),
    );
    $payoutAccount = $result->payoutAccount;

    expect($result->wasCreated)->toBeTrue()
        ->and($payoutAccount->user_id)->toBe($user->id)
        ->and($payoutAccount->provider)->toBe(PaymentProvider::Fake)
        ->and($payoutAccount->provider_recipient_code)->not->toContain($accountNumber)
        ->and($payoutAccount->bank_code)->toBe('057')
        ->and($payoutAccount->bank_name)->toBe('Demo Bank')
        ->and($payoutAccount->account_name)->toBe('Demo Customer')
        ->and($payoutAccount->account_last_four)->toBe('1234')
        ->and($payoutAccount->currency)->toBe(Currency::Ngn)
        ->and($payoutAccount->verified_at)->not->toBeNull()
        ->and(Schema::getColumnListing('payout_accounts'))->not->toContain('account_number')
        ->and(json_encode($payoutAccount->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain($accountNumber)
        ->and($payoutAccount->toArray())->not->toHaveKeys([
            'user_id',
            'provider_recipient_code',
            'account_last_four',
        ]);

    Event::assertDispatched(PayoutAccountVerified::class, function (PayoutAccountVerified $event) use ($payoutAccount): bool {
        expect(array_keys(get_object_vars($event)))->toBe(['payoutAccount'])
            ->and($event->payoutAccount->is($payoutAccount))->toBeTrue();

        return true;
    });
});

it('replaces the existing account in place and reports the outcome explicitly', function (): void {
    Event::fake([PayoutAccountVerified::class]);
    $user = User::factory()->create();
    $action = app(RegisterPayoutAccount::class);

    $created = $action->handle($user, new RegisterPayoutAccountInput('0000001234', '057'));
    $replaced = $action->handle($user, new RegisterPayoutAccountInput('0000009876', '058'));

    expect($created->wasCreated)->toBeTrue()
        ->and($replaced->wasCreated)->toBeFalse()
        ->and($replaced->payoutAccount->id)->toBe($created->payoutAccount->id)
        ->and($replaced->payoutAccount->bank_code)->toBe('058')
        ->and($replaced->payoutAccount->account_last_four)->toBe('9876')
        ->and(PayoutAccount::query()->whereBelongsTo($user)->count())->toBe(1);

    Event::assertDispatchedTimes(PayoutAccountVerified::class, 2);
});

it('notifies listeners only after the local transaction commits', function (): void {
    $transactionLevels = [];
    Event::listen(PayoutAccountVerified::class, function () use (&$transactionLevels): void {
        $transactionLevels[] = DB::connection()->transactionLevel();
    });

    app(RegisterPayoutAccount::class)->handle(
        User::factory()->create(),
        new RegisterPayoutAccountInput('0000001234', '057'),
    );

    expect($transactionLevels)->toBe([0])
        ->and(PayoutAccount::query()->count())->toBe(1);
});

it('preserves the previous account and emits no event when the provider rejects replacement', function (): void {
    $user = User::factory()->create();
    $original = app(RegisterPayoutAccount::class)->handle(
        $user,
        new RegisterPayoutAccountInput('0000001234', '057'),
    )->payoutAccount;
    $originalDestination = [
        $original->id,
        $original->provider->value,
        $original->provider_recipient_code,
        $original->bank_code,
        $original->bank_name,
        $original->account_name,
        $original->account_last_four,
        $original->currency->value,
    ];
    Event::fake([PayoutAccountVerified::class]);
    config()->set('payments.fake.payout_account_scenario', 'rejected');

    try {
        app(RegisterPayoutAccount::class)->handle(
            $user,
            new RegisterPayoutAccountInput('0000009876', '058'),
        );
        test()->fail('The rejected replacement should throw.');
    } catch (PaymentProviderException $exception) {
        expect($exception->failure)->toBe(PaymentProviderFailure::RecipientRejected);
    }

    $preserved = PayoutAccount::query()->findOrFail($original->id);

    expect([
        $preserved->id,
        $preserved->provider->value,
        $preserved->provider_recipient_code,
        $preserved->bank_code,
        $preserved->bank_name,
        $preserved->account_name,
        $preserved->account_last_four,
        $preserved->currency->value,
    ])->toBe($originalDestination);
    Event::assertNotDispatched(PayoutAccountVerified::class);
});

it('maps a recipient identity conflict while preserving the previous account and suppressing its event', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $action = app(RegisterPayoutAccount::class);
    $original = $action->handle(
        $user,
        new RegisterPayoutAccountInput('0000001234', '057'),
    )->payoutAccount;
    $originalDestination = [
        $original->id,
        $original->provider->value,
        $original->provider_recipient_code,
        $original->bank_code,
        $original->bank_name,
        $original->account_name,
        $original->account_last_four,
        $original->currency->value,
    ];
    $conflictingInput = new RegisterPayoutAccountInput('0000009876', '058');
    $conflictingRecipient = app(FakeTransferRecipientGateway::class)
        ->createRecipient($conflictingInput);

    PayoutAccount::factory()->for($otherUser)->create([
        'provider' => $conflictingRecipient->provider,
        'provider_recipient_code' => $conflictingRecipient->recipientCode,
    ]);
    Event::fake([PayoutAccountVerified::class]);

    expect(fn () => $action->handle($user, $conflictingInput))
        ->toThrow(PayoutAccountConflictException::class);

    $preserved = PayoutAccount::query()->findOrFail($original->id);

    expect([
        $preserved->id,
        $preserved->provider->value,
        $preserved->provider_recipient_code,
        $preserved->bank_code,
        $preserved->bank_name,
        $preserved->account_name,
        $preserved->account_last_four,
        $preserved->currency->value,
    ])->toBe($originalDestination);
    Event::assertNotDispatched(PayoutAccountVerified::class);
});

it('does not reinterpret or replace a stored account when the configured provider is unavailable', function (): void {
    $user = User::factory()->create();
    $original = app(RegisterPayoutAccount::class)->handle(
        $user,
        new RegisterPayoutAccountInput('0000001234', '057'),
    )->payoutAccount;
    config()->set('payments.default', PaymentProvider::Paystack->value);
    Event::fake([PayoutAccountVerified::class]);

    try {
        app(RegisterPayoutAccount::class)->handle(
            $user,
            new RegisterPayoutAccountInput('0000009876', '058'),
        );
        test()->fail('Paystack without a test credential should fail safely.');
    } catch (PaymentProviderException $exception) {
        expect($exception->failure)->toBe(PaymentProviderFailure::Unavailable);
    }

    $preserved = PayoutAccount::query()->findOrFail($original->id);

    expect($preserved->provider)->toBe(PaymentProvider::Fake)
        ->and($preserved->account_last_four)->toBe('1234')
        ->and(PayoutAccount::query()->whereBelongsTo($user)->count())->toBe(1);
    Event::assertNotDispatched(PayoutAccountVerified::class);
});

it('enforces payout account invariants in postgres', function (array $invalid): void {
    $payoutAccount = PayoutAccount::factory()->create();

    expect(fn () => DB::table('payout_accounts')->where('id', $payoutAccount->id)->update($invalid))
        ->toThrow(QueryException::class);
})->with([
    'unknown provider' => [['provider' => 'unknown']],
    'invalid bank code' => [['bank_code' => '57']],
    'invalid last four' => [['account_last_four' => '12x4']],
    'unsupported currency' => [['currency' => 'USD']],
]);

it('enforces one account per user and provider-scoped recipient identity', function (): void {
    $payoutAccount = PayoutAccount::factory()->create();

    expect(fn () => PayoutAccount::factory()->create(['user_id' => $payoutAccount->user_id]))
        ->toThrow(QueryException::class)
        ->and(fn () => PayoutAccount::factory()->create([
            'provider' => $payoutAccount->provider,
            'provider_recipient_code' => $payoutAccount->provider_recipient_code,
        ]))->toThrow(QueryException::class);

    $otherProvider = PayoutAccount::factory()->create([
        'provider' => PaymentProvider::Paystack,
        'provider_recipient_code' => $payoutAccount->provider_recipient_code,
    ]);

    expect($otherProvider->provider)->toBe(PaymentProvider::Paystack);
});

it('preserves the payout destination by restricting deletion of its customer', function (): void {
    $payoutAccount = PayoutAccount::factory()->create();
    $user = $payoutAccount->user()->firstOrFail();

    expect(fn () => $user->delete())->toThrow(QueryException::class)
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(PayoutAccount::query()->whereKey($payoutAccount->id)->exists())->toBeTrue();
});

it('rejects system identities and caller-owned transactions before provider work', function (): void {
    $system = User::factory()->system()->create();

    expect(fn () => app(RegisterPayoutAccount::class)->handle(
        $system,
        new RegisterPayoutAccountInput('0000001234', '057'),
    ))->toThrow(AuthorizationException::class);

    $customer = User::factory()->create();

    expect(fn () => DB::transaction(
        fn () => app(RegisterPayoutAccount::class)->handle(
            $customer,
            new RegisterPayoutAccountInput('0000001234', '057'),
        ),
    ))->toThrow(
        LogicException::class,
        'Payout account registration cannot run inside an existing database transaction.',
    );
});

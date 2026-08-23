<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\Data\Payments\CreatedTransferRecipient;
use App\Data\Payouts\PayoutAccountRegistrationResult;
use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Enums\AccountType;
use App\Events\PayoutAccountVerified;
use App\Exceptions\Payouts\PayoutAccountBusyException;
use App\Exceptions\Payouts\PayoutAccountConflictException;
use App\Infrastructure\Payments\PaymentProviderRegistry;
use App\Models\PayoutAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use SensitiveParameter;
use Throwable;

final readonly class RegisterPayoutAccount
{
    private const RECIPIENT_UNIQUE_CONSTRAINT = 'payout_accounts_provider_recipient_unique';

    private const int DRIVER_ERROR_MESSAGE_INDEX = 2;

    private const int DEFAULT_LOCK_LEASE_SECONDS = 60;

    private const int DEFAULT_LOCK_WAIT_SECONDS = 5;

    public function __construct(
        private PaymentProviderRegistry $paymentProviders,
        #[Config('payments.payout_account_lock.seconds', self::DEFAULT_LOCK_LEASE_SECONDS)]
        private int $lockLeaseSeconds,
        #[Config('payments.payout_account_lock.wait_seconds', self::DEFAULT_LOCK_WAIT_SECONDS)]
        private int $lockWaitSeconds,
    ) {}

    public function handle(
        User $user,
        #[SensitiveParameter] RegisterPayoutAccountInput $input,
    ): PayoutAccountRegistrationResult {
        if ($user->account_type !== AccountType::Customer) {
            throw new AuthorizationException;
        }

        if (DB::connection()->transactionLevel() > 0) {
            throw new LogicException('Payout account registration cannot run inside an existing database transaction.');
        }

        /*
         * This per-user lock covers the provider call and local replacement so
         * two requests cannot create competing recipients for the same customer.
         * A database transaction alone cannot lock or roll back provider work.
         */
        $lock = Cache::lock("payout-account:user:{$user->id}", $this->lockLeaseSeconds);

        try {
            /** @var PayoutAccountRegistrationResult $registration */
            $registration = $lock->block(
                $this->lockWaitSeconds,
                fn (): PayoutAccountRegistrationResult => $this->createAndSaveAccount($user, $input),
            );
        } catch (LockTimeoutException $exception) {
            throw new PayoutAccountBusyException(previous: $exception);
        }

        $this->logSavedAccount($registration);

        return $registration;
    }

    private function createAndSaveAccount(
        User $user,
        #[SensitiveParameter] RegisterPayoutAccountInput $input,
    ): PayoutAccountRegistrationResult {
        $createdRecipient = $this->paymentProviders
            ->defaultRecipientGateway()
            ->createRecipient($input);

        return $this->saveAccount($user, $createdRecipient);
    }

    private function saveAccount(
        User $user,
        CreatedTransferRecipient $createdRecipient,
    ): PayoutAccountRegistrationResult {
        try {
            return DB::transaction(function () use ($user, $createdRecipient): PayoutAccountRegistrationResult {
                /*
                 * The user row is the lock anchor when no payout-account row exists.
                 * PayoutAccountVerified waits for this transaction to commit.
                 */
                User::query()
                    ->whereKey($user->id)
                    ->where('account_type', AccountType::Customer)
                    ->lockForUpdate()
                    ->firstOrFail();

                $payoutAccount = PayoutAccount::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();
                $wasCreated = $payoutAccount === null;
                $payoutAccount ??= new PayoutAccount(['user_id' => $user->id]);

                $payoutAccount->fill([
                    'provider' => $createdRecipient->provider,
                    'provider_recipient_code' => $createdRecipient->recipientCode,
                    'bank_code' => $createdRecipient->bankCode,
                    'bank_name' => $createdRecipient->bankName,
                    'account_name' => $createdRecipient->accountName,
                    'account_last_four' => $createdRecipient->accountLastFour,
                    'currency' => $createdRecipient->currency,
                    'verified_at' => now(),
                ])->save();

                PayoutAccountVerified::dispatch($payoutAccount);

                return new PayoutAccountRegistrationResult($payoutAccount, $wasCreated);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isRecipientCodeConflict($exception)) {
                throw $exception;
            }

            throw new PayoutAccountConflictException(previous: $exception);
        }
    }

    private function isRecipientCodeConflict(UniqueConstraintViolationException $exception): bool
    {
        /*
         * PDO errorInfo uses index 0 for SQLSTATE, 1 for the driver code, and
         * 2 for the driver message that contains PostgreSQL's constraint name.
         */
        $driverMessage = $exception->errorInfo[self::DRIVER_ERROR_MESSAGE_INDEX] ?? null;

        return is_string($driverMessage)
            && str_contains($driverMessage, self::RECIPIENT_UNIQUE_CONSTRAINT);
    }

    private function logSavedAccount(PayoutAccountRegistrationResult $registration): void
    {
        $payoutAccount = $registration->payoutAccount;

        try {
            Log::info('payout_account.saved', [
                'user_id' => $payoutAccount->user_id,
                'payout_account_id' => $payoutAccount->id,
                'provider' => $payoutAccount->provider->value,
                'result' => $registration->wasCreated ? 'created' : 'replaced',
            ]);
        } catch (Throwable $exception) {
            $this->reportLogFailure($exception);
        }
    }

    private function reportLogFailure(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            /*
             * The account is already committed. A reporting failure must not
             * change the successful registration returned to the customer.
             */
        }
    }
}

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
use LogicException;
use SensitiveParameter;

final readonly class RegisterPayoutAccount
{
    private const RECIPIENT_UNIQUE_CONSTRAINT = 'payout_accounts_provider_recipient_unique';

    public function __construct(
        private PaymentProviderRegistry $paymentProviders,
        #[Config('payments.payout_account_lock.seconds', 30)] private int $lockSeconds,
        #[Config('payments.payout_account_lock.wait_seconds', 5)] private int $lockWaitSeconds,
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

        $lock = Cache::lock("payout-account:user:{$user->id}", $this->lockSeconds);

        try {
            /** @var PayoutAccountRegistrationResult $result */
            $result = $lock->block(
                $this->lockWaitSeconds,
                fn (): PayoutAccountRegistrationResult => $this->registerWhileLocked($user, $input),
            );

            return $result;
        } catch (LockTimeoutException $exception) {
            throw new PayoutAccountBusyException(previous: $exception);
        }
    }

    private function registerWhileLocked(
        User $user,
        #[SensitiveParameter] RegisterPayoutAccountInput $input,
    ): PayoutAccountRegistrationResult {
        $recipient = $this->paymentProviders
            ->defaultRecipientGateway()
            ->createRecipient($input);

        return $this->persistRecipient($user, $recipient);
    }

    private function persistRecipient(
        User $user,
        CreatedTransferRecipient $recipient,
    ): PayoutAccountRegistrationResult {
        try {
            return DB::transaction(function () use ($user, $recipient): PayoutAccountRegistrationResult {
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
                    'provider' => $recipient->provider,
                    'provider_recipient_code' => $recipient->recipientCode,
                    'bank_code' => $recipient->bankCode,
                    'bank_name' => $recipient->bankName,
                    'account_name' => $recipient->accountName,
                    'account_last_four' => $recipient->accountLastFour,
                    'currency' => $recipient->currency,
                    'verified_at' => now(),
                ])->save();

                PayoutAccountVerified::dispatch($payoutAccount);

                return new PayoutAccountRegistrationResult($payoutAccount, $wasCreated);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $databaseMessage = $exception->errorInfo[2] ?? '';

            if (! is_string($databaseMessage)
                || ! str_contains($databaseMessage, self::RECIPIENT_UNIQUE_CONSTRAINT)) {
                throw $exception;
            }

            throw new PayoutAccountConflictException(previous: $exception);
        }
    }
}

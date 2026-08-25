<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Payments\TransferRecipientGateway;
use App\Data\Payments\CreatedTransferRecipient;
use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use Illuminate\Support\Facades\Redis;
use SensitiveParameter;

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

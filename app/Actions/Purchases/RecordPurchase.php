<?php

declare(strict_types=1);

namespace App\Actions\Purchases;

use App\Data\Purchases\RecordPurchaseInput;
use App\Data\Purchases\RecordPurchaseResult;
use App\Enums\AccountType;
use App\Events\PurchaseCompleted;
use App\Exceptions\Purchases\PurchaseReferenceConflictException;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class RecordPurchase
{
    public function handle(RecordPurchaseInput $input): RecordPurchaseResult
    {
        if (! $input->amount->isPositive()) {
            throw new InvalidArgumentException('A completed purchase amount must be positive.');
        }

        return DB::transaction(function () use ($input): RecordPurchaseResult {
            User::query()
                ->whereKey($input->userId)
                ->where('account_type', AccountType::Customer)
                ->firstOrFail();

            $purchase = Purchase::query()->createOrFirst(
                ['external_reference' => $input->externalReference],
                [
                    'user_id' => $input->userId,
                    'amount_minor' => $input->amount->amountMinor,
                    'currency' => $input->amount->currency,
                    'completed_at' => $input->completedAt,
                    'correlation_id' => (string) Str::ulid(),
                ],
            );

            if (! $purchase->wasRecentlyCreated) {
                $this->ensureMatchingDelivery($purchase, $input);

                return new RecordPurchaseResult($purchase, wasDuplicate: true);
            }

            PurchaseCompleted::dispatch($purchase);

            return new RecordPurchaseResult($purchase, wasDuplicate: false);
        });
    }

    private function ensureMatchingDelivery(Purchase $purchase, RecordPurchaseInput $input): void
    {
        $matches = $purchase->user_id === $input->userId
            && $purchase->amount_minor === $input->amount->amountMinor
            && $purchase->completed_at->equalTo($input->completedAt);

        if (! $matches) {
            throw new PurchaseReferenceConflictException;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountType;
use App\Models\PayoutAccount;
use App\Models\User;

final class PayoutAccountPolicy
{
    public function update(User $user, PayoutAccount $payoutAccount): bool
    {
        return $user->account_type === AccountType::Customer
            && $payoutAccount->user_id === $user->id;
    }
}

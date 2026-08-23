<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountType;
use App\Models\User;

final class UserPolicy
{
    public function viewAchievements(User $actor, User $target): bool
    {
        return $actor->account_type === AccountType::Customer
            && $actor->is($target);
    }
}

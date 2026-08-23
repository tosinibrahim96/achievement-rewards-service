<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Achievements\GetUserAchievementProgress;
use App\Http\Resources\AchievementProgressResource;
use App\Models\User;

final class AchievementProgressController extends Controller
{
    public function __construct(
        private readonly GetUserAchievementProgress $getUserAchievementProgress,
    ) {}

    public function show(User $user): AchievementProgressResource
    {
        return new AchievementProgressResource(
            $this->getUserAchievementProgress->handle($user),
        );
    }
}

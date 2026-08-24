<?php

declare(strict_types=1);

use App\Enums\TokenAbility;
use App\Http\Controllers\AchievementProgressController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

Route::get('/', static fn (): JsonResponse => response()->json([
    'name' => config('app.name'),
    'status' => 'ok',
]));

Route::get('/users/{user}/achievements', [AchievementProgressController::class, 'show'])
    ->whereNumber('user')
    ->middleware([
        'auth:sanctum',
        'abilities:'.TokenAbility::AchievementsRead->value,
        'customer-account',
        'can:viewAchievements,user',
    ])
    ->name('users.achievements.show');

Route::get('/openapi.yaml', static function (): BinaryFileResponse {
    Gate::authorize('viewScalar');

    return response()->file(base_path('openapi.yaml'), [
        'Content-Type' => 'application/yaml',
    ]);
})->name('openapi');

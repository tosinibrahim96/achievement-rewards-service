<?php

declare(strict_types=1);

use App\Enums\TokenAbility;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PayoutAccountController;
use App\Http\Controllers\PurchaseController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:registration')
        ->name('register');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login');
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('me');
});

Route::put('/me/payout-account', [PayoutAccountController::class, 'update'])
    ->middleware([
        'auth:sanctum',
        'abilities:'.TokenAbility::PayoutAccountsWrite->value,
        'customer-account',
        'throttle:payout-account',
    ])
    ->name('me.payout-account.update');

Route::post('/internal/purchases', [PurchaseController::class, 'store'])
    ->middleware([
        'auth:sanctum',
        'abilities:'.TokenAbility::PurchasesWrite->value,
        'system-account',
        'throttle:purchase-ingestion',
    ])
    ->name('internal.purchases.store');

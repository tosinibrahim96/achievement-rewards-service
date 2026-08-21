<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', static fn (): JsonResponse => response()->json([
    'name' => config('app.name'),
    'status' => 'ok',
]));

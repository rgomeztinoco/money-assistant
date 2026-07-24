<?php

use App\Http\Controllers\Api\OpenClawTransportController;
use App\Http\Middleware\AuthenticateOpenClaw;
use Illuminate\Support\Facades\Route;

Route::post('/openclaw/v1/transport', OpenClawTransportController::class)
    ->middleware(['throttle:openclaw', AuthenticateOpenClaw::class])
    ->name('openclaw.transport');

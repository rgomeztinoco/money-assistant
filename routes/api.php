<?php

use App\Http\Controllers\Api\OpenClawTransportController;
use App\Http\Middleware\AuditOpenClawRequest;
use App\Http\Middleware\AuthenticateOpenClaw;
use App\Http\Middleware\AuthorizeOpenClawCapability;
use App\Http\Middleware\ThrottleAuthenticatedOpenClaw;
use Illuminate\Support\Facades\Route;

Route::post('/openclaw/v1/transport', OpenClawTransportController::class)
    ->middleware([
        'throttle:openclaw-ingress',
        AuthenticateOpenClaw::class,
        AuditOpenClawRequest::class,
        ThrottleAuthenticatedOpenClaw::class,
        AuthorizeOpenClawCapability::class,
    ])
    ->name('openclaw.transport');

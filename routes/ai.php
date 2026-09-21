<?php

use App\Http\Middleware\AuthenticateAgentToken;
use App\Mcp\Servers\FinancialDataServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::middleware(['auth:sanctum', AuthenticateAgentToken::class, 'throttle:financial-mcp'])->group(function () {
    Mcp::web('/mcp', FinancialDataServer::class)->name('mcp');
});

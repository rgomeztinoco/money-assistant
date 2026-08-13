<?php

namespace App\Http\Controllers\Settings;

use App\Actions\NotificationIngestion\ReadGmailConnectionStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionsController extends Controller
{
    public function edit(Request $request, ReadGmailConnectionStatus $readStatus): Response
    {
        return Inertia::render('settings/connections', [
            'gmail' => $readStatus->handle(),
        ]);
    }
}

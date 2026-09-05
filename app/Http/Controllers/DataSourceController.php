<?php

namespace App\Http\Controllers;

use App\Actions\NotificationIngestion\ReadGmailConnectionStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DataSourceController extends Controller
{
    public function __invoke(
        Request $request,
        ReadGmailConnectionStatus $readGmailConnectionStatus,
    ): Response {
        return Inertia::render('data-sources/gmail', [
            'gmail' => $readGmailConnectionStatus->handle($request->user()),
        ]);
    }
}

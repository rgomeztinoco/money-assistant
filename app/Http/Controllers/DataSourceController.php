<?php

namespace App\Http\Controllers;

use App\Actions\NotificationIngestion\ReadGmailConnectionStatus;
use App\Actions\NotificationIngestion\ReadGmailReview;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DataSourceController extends Controller
{
    public function __invoke(
        Request $request,
        ReadGmailConnectionStatus $readGmailConnectionStatus,
        ReadGmailReview $readGmailReview,
    ): Response {
        $requestedView = $request->query('view');

        return Inertia::render('data-sources/gmail', [
            'gmail' => $readGmailConnectionStatus->handle($request->user()),
            'review' => $readGmailReview->handle(
                $request->user(),
                is_string($requestedView) ? $requestedView : 'all',
            ),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\NotificationIngestion\ReadParserProfileSourceMessage;
use App\Models\GmailMessageDiscovery;
use App\Models\ParserProfile;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ParserProfileSourceMessageController extends Controller
{
    public function show(
        Request $request,
        GmailMessageDiscovery $gmailMessageDiscovery,
        ReadParserProfileSourceMessage $readSourceMessage,
    ): Response {
        $source = $readSourceMessage->handle(
            $request->user(),
            $gmailMessageDiscovery,
        );

        return Inertia::render('parser-profiles/create', [
            'source' => [
                'discovery_id' => $gmailMessageDiscovery->id,
                ...$source,
            ],
            'profiles' => ParserProfile::query()
                ->whereBelongsTo($request->user(), 'owner')
                ->latest()
                ->get(['id', 'name', 'current_version'])
                ->map(fn (ParserProfile $profile): array => [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'current_version' => $profile->current_version,
                ])
                ->all(),
        ]);
    }
}

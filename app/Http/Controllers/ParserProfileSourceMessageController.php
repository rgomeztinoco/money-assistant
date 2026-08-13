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
        $source = $readSourceMessage->handle($gmailMessageDiscovery);

        return Inertia::render('parser-profiles/create', [
            'source' => [
                'discovery_id' => $gmailMessageDiscovery->id,
                ...$source,
            ],
            'profiles' => ParserProfile::query()
                ->with(['formats' => fn ($query) => $query->oldest('id')])
                ->latest()
                ->get(['id', 'name'])
                ->map(fn (ParserProfile $profile): array => [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'formats' => $profile->formats->map(fn ($format): array => [
                        'id' => $format->id,
                        'name' => $format->name,
                    ])->all(),
                ])
                ->all(),
        ]);
    }
}

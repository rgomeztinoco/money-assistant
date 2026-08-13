<?php

namespace App\Http\Controllers;

use App\Models\ParserProfile;
use App\Models\SpendingNotificationFormat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SpendingNotificationFormatActivationController extends Controller
{
    public function store(
        Request $request,
        ParserProfile $parserProfile,
        SpendingNotificationFormat $spendingNotificationFormat,
    ): RedirectResponse {
        $this->ownedFormat($request, $parserProfile, $spendingNotificationFormat)
            ->forceFill(['enabled_at' => now()])
            ->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Format enabled.')]);

        return to_route('parser_profiles.index');
    }

    public function destroy(
        Request $request,
        ParserProfile $parserProfile,
        SpendingNotificationFormat $spendingNotificationFormat,
    ): RedirectResponse {
        $this->ownedFormat($request, $parserProfile, $spendingNotificationFormat)
            ->forceFill(['enabled_at' => null])
            ->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Format disabled.')]);

        return to_route('parser_profiles.index');
    }

    private function ownedFormat(
        Request $request,
        ParserProfile $profile,
        SpendingNotificationFormat $format,
    ): SpendingNotificationFormat {
        ParserProfile::query()
            ->findOrFail($profile->id);

        return SpendingNotificationFormat::query()
            ->whereBelongsTo($profile)
            ->findOrFail($format->id);
    }
}

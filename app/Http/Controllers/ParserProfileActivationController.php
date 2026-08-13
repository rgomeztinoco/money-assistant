<?php

namespace App\Http\Controllers;

use App\Models\ParserProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ParserProfileActivationController extends Controller
{
    public function store(Request $request, ParserProfile $parserProfile): RedirectResponse
    {
        $this->ownedProfile($request, $parserProfile)
            ->forceFill(['enabled_at' => now()])
            ->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Parser Profile enabled.')]);

        return to_route('parser_profiles.index');
    }

    public function destroy(Request $request, ParserProfile $parserProfile): RedirectResponse
    {
        $this->ownedProfile($request, $parserProfile)
            ->forceFill(['enabled_at' => null])
            ->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Parser Profile disabled.')]);

        return to_route('parser_profiles.index');
    }

    private function ownedProfile(Request $request, ParserProfile $parserProfile): ParserProfile
    {
        return ParserProfile::query()
            ->whereBelongsTo($request->user(), 'owner')
            ->findOrFail($parserProfile->id);
    }
}

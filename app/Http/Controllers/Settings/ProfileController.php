<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Security\InvalidateOtherSessions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __construct(
        private InvalidateOtherSessions $invalidateOtherSessions,
    ) {}

    /**
     * Show the user's profile settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/profile');
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        $request->user()->save();

        if ($request->user()->wasChanged('email')) {
            $this->invalidateOtherSessions->handle(
                $request->user(),
                $request->session()->getId(),
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }
}

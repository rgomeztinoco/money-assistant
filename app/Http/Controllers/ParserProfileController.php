<?php

namespace App\Http\Controllers;

use App\Actions\NotificationIngestion\CreateParserProfile;
use App\Actions\NotificationIngestion\ReadParserProfileSourceMessages;
use App\Http\Requests\StoreParserProfileRequest;
use App\Http\Requests\UpdateParserProfileRequest;
use App\Models\ParserProfile;
use App\Models\SpendingNotificationFormat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class ParserProfileController extends Controller
{
    public function index(
        Request $request,
        ReadParserProfileSourceMessages $readSourceMessages,
    ): Response {
        return Inertia::render('parser-profiles/index', [
            'profiles' => ParserProfile::query()
                ->whereBelongsTo($request->user(), 'owner')
                ->with(['formats' => fn ($query) => $query->oldest('id')])
                ->latest('id')
                ->get()
                ->map(fn (ParserProfile $profile): array => [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'trusted_sender_address' => $profile->trusted_sender_address,
                    'authentication_mechanism' => $profile->authentication_mechanism,
                    'authenticated_domain' => $profile->authenticated_domain,
                    'enabled' => $profile->isEnabled(),
                    'formats' => $profile->formats->map(fn (SpendingNotificationFormat $format): array => [
                        'id' => $format->id,
                        'name' => $format->name,
                        'purpose' => $format->purpose->value,
                        'mime_source' => $format->mime_source,
                        'enabled' => $format->enabled_at !== null,
                    ])->all(),
                ])->all(),
            'source_messages' => $readSourceMessages->handle($request->user()),
        ]);
    }

    public function store(
        StoreParserProfileRequest $request,
        CreateParserProfile $createParserProfile,
    ): RedirectResponse {
        try {
            $createParserProfile->handle(
                $request->user(),
                $request->validated(),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'profile' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Parser Profile created and source message processed.'),
        ]);

        return to_route('parser_profiles.index');
    }

    public function update(
        UpdateParserProfileRequest $request,
        ParserProfile $parserProfile,
    ): RedirectResponse {
        $this->ownedProfile($request, $parserProfile)->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Parser Profile updated.'),
        ]);

        return to_route('parser_profiles.index');
    }

    public function destroy(Request $request, ParserProfile $parserProfile): RedirectResponse
    {
        $this->ownedProfile($request, $parserProfile)->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Parser Profile deleted.'),
        ]);

        return to_route('parser_profiles.index');
    }

    private function ownedProfile(Request $request, ParserProfile $parserProfile): ParserProfile
    {
        return ParserProfile::query()
            ->whereBelongsTo($request->user(), 'owner')
            ->findOrFail($parserProfile->id);
    }
}

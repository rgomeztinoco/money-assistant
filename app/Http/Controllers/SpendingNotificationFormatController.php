<?php

namespace App\Http\Controllers;

use App\Actions\NotificationIngestion\ValidateSpendingNotificationFormat;
use App\Http\Requests\StoreParserProfileRequest;
use App\Http\Requests\StoreSpendingNotificationFormatRequest;
use App\Http\Requests\UpdateSpendingNotificationFormatRequest;
use App\Models\ParserProfile;
use App\Models\SpendingNotificationFormat;
use App\ValidatedSpendingNotificationFormat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

class SpendingNotificationFormatController extends Controller
{
    public function store(
        StoreSpendingNotificationFormatRequest $request,
        ParserProfile $parserProfile,
        ValidateSpendingNotificationFormat $validateFormat,
    ): RedirectResponse {
        $validated = $this->validatedFormat($request, $parserProfile, $validateFormat);
        $validated->format->forceFill([
            'parser_profile_id' => $parserProfile->id,
            'enabled_at' => now(),
        ])->save();

        return $this->success(__('Spending Notification Format created.'));
    }

    public function update(
        UpdateSpendingNotificationFormatRequest $request,
        ParserProfile $parserProfile,
        SpendingNotificationFormat $spendingNotificationFormat,
        ValidateSpendingNotificationFormat $validateFormat,
    ): RedirectResponse {
        $validated = $this->validatedFormat($request, $parserProfile, $validateFormat);
        $spendingNotificationFormat->forceFill([
            'parser_profile_id' => $parserProfile->id,
            'name' => $validated->format->name,
            'mime_source' => $validated->format->mime_source,
            'purpose' => $validated->format->purpose,
            'rule_identifier' => $validated->format->rule_identifier,
            'definition' => $validated->format->definition,
            'enabled_at' => now(),
        ])->save();

        return $this->success(__('Spending Notification Format updated and enabled.'));
    }

    public function destroy(
        Request $request,
        ParserProfile $parserProfile,
        SpendingNotificationFormat $spendingNotificationFormat,
    ): RedirectResponse {
        $this->ownedProfile($request, $parserProfile);
        abort_unless($spendingNotificationFormat->parser_profile_id === $parserProfile->id, 404);
        $spendingNotificationFormat->delete();

        return $this->success(__('Spending Notification Format deleted.'));
    }

    private function validatedFormat(
        StoreParserProfileRequest $request,
        ParserProfile $profile,
        ValidateSpendingNotificationFormat $validateFormat,
    ): ValidatedSpendingNotificationFormat {
        try {
            return $validateFormat->handle($request->user(), $request->validated(), $profile);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['profile' => $exception->getMessage()]);
        }
    }

    private function success(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('parser_profiles.index');
    }

    private function ownedProfile(Request $request, ParserProfile $profile): ParserProfile
    {
        return ParserProfile::query()
            ->whereBelongsTo($request->user(), 'owner')
            ->findOrFail($profile->id);
    }
}

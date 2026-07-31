<?php

namespace App\Http\Controllers;

use App\Actions\NotificationIngestion\PreviewParserProfile;
use App\Http\Requests\StoreParserProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

class ParserProfilePreviewController extends Controller
{
    public function store(
        StoreParserProfileRequest $request,
        PreviewParserProfile $previewParserProfile,
    ): RedirectResponse {
        try {
            Inertia::flash(
                'parser_profile_preview',
                $previewParserProfile->handle(
                    $request->user(),
                    $request->validated(),
                ),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'profile' => $exception->getMessage(),
            ]);
        }

        return back();
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\NotificationIngestion\ApproveParserProfileVersion;
use App\Actions\NotificationIngestion\ReadParserProfileHealth;
use App\Actions\NotificationIngestion\ReadParserProfileSourceMessages;
use App\Http\Requests\StoreParserProfileRequest;
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
        ReadParserProfileHealth $readParserProfileHealth,
        ReadParserProfileSourceMessages $readSourceMessages,
    ): Response {
        $profileHealth = $readParserProfileHealth->handle($request->user());

        return Inertia::render('parser-profiles/index', [
            ...$profileHealth,
            'source_messages' => $readSourceMessages->handle($request->user()),
        ]);
    }

    public function store(
        StoreParserProfileRequest $request,
        ApproveParserProfileVersion $approveParserProfileVersion,
    ): RedirectResponse {
        try {
            $approveParserProfileVersion->handle(
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
}

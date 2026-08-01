<?php

namespace App\Http\Controllers;

use App\Actions\Integrations\ReplayIntegrationIncident;
use App\Models\IntegrationIncident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;

class IntegrationIncidentReplayController extends Controller
{
    public function __invoke(
        Request $request,
        IntegrationIncident $integrationIncident,
        ReplayIntegrationIncident $replayIntegrationIncident,
    ): RedirectResponse {
        try {
            $replayIntegrationIncident->handle(
                $request->user(),
                $integrationIncident->id,
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['replay' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Integration work queued for replay.'),
        ]);

        return to_route('dashboard');
    }
}

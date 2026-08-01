<?php

namespace App\Http\Controllers;

use App\Actions\Integrations\AcknowledgeIntegrationIncident;
use App\Models\IntegrationIncident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IntegrationIncidentAcknowledgementController extends Controller
{
    public function __invoke(
        Request $request,
        IntegrationIncident $integrationIncident,
        AcknowledgeIntegrationIncident $acknowledgeIntegrationIncident,
    ): RedirectResponse {
        $acknowledgeIntegrationIncident->handle(
            $request->user(),
            $integrationIncident->id,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Integration incident acknowledged.'),
        ]);

        return to_route('dashboard');
    }
}

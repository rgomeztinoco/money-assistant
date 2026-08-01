<?php

namespace App\Http\Controllers\Settings;

use App\Actions\OpenClaw\CompleteFinancialDeletion;
use App\Actions\OpenClaw\CompleteFinancialExport;
use App\Actions\OpenClaw\ReadHighImpactOperation;
use App\Exceptions\OpenClawConfirmationRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteHighImpactOperationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HighImpactOperationController extends Controller
{
    public function __construct(
        private ReadHighImpactOperation $readHighImpactOperation,
        private CompleteFinancialExport $completeFinancialExport,
        private CompleteFinancialDeletion $completeFinancialDeletion,
    ) {}

    public function show(Request $request, string $operationId): Response
    {
        return Inertia::render('settings/high-impact-operation', [
            'operation' => $this->readHighImpactOperation->handle(
                $request->user(),
                $operationId,
            ),
        ]);
    }

    public function complete(
        CompleteHighImpactOperationRequest $request,
        string $operationId,
    ): StreamedResponse|RedirectResponse {
        try {
            $operation = $this->readHighImpactOperation->handle($request->user(), $operationId);

            if ($operation['kind'] === 'financial_deletion') {
                $redirectRoute = $this->completeFinancialDeletion->handle(
                    owner: $request->user(),
                    operationId: $operationId,
                    expectedRevision: $request->integer('expected_revision'),
                    payloadDigest: (string) $request->validated('payload_digest'),
                    webApprovalDigest: hash('sha256', $request->session()->getId()),
                );

                return to_route($redirectRoute);
            }

            $export = $this->completeFinancialExport->handle(
                owner: $request->user(),
                operationId: $operationId,
                expectedRevision: $request->integer('expected_revision'),
                payloadDigest: (string) $request->validated('payload_digest'),
                webApprovalDigest: hash('sha256', $request->session()->getId()),
            );
        } catch (OpenClawConfirmationRejected $exception) {
            abort(409, $exception->getMessage());
        }

        return response()->streamDownload(
            static fn () => $export->output(),
            'money-assistant-export-'.now()->format('Y-m-d-His').'.json',
            ['Content-Type' => 'application/json'],
        );
    }
}

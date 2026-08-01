<?php

namespace App\Http\Controllers\Api;

use App\Actions\OpenClaw\PrepareFinancialDeletion;
use App\Actions\OpenClaw\PrepareFinancialExport;
use App\Exceptions\CategoryOperationBlocked;
use App\Exceptions\IdempotencyKeyConflict;
use App\Exceptions\StaleCategoryRevision;
use App\Exceptions\StaleReceiptBreakdownRevision;
use App\Models\OpenClawPendingOperation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class OpenClawHighImpactPreparationResponder
{
    public function __construct(
        private PrepareFinancialExport $prepareFinancialExport,
        private PrepareFinancialDeletion $prepareFinancialDeletion,
    ) {}

    public function handle(Request $request, ?User $owner, string $capability): JsonResponse
    {
        $input = $request->attributes->get('openclaw.input');
        $interaction = $request->attributes->get('openclaw.interaction');
        $serviceKeyId = $request->attributes->get('openclaw.key_id');
        $schemaVersion = $request->attributes->get('openclaw.schema_version');
        $interactionDigest = $request->attributes->get('openclaw.interaction_digest');

        if ($owner === null
            || ! is_array($input)
            || ! is_array($interaction)
            || ! is_string($serviceKeyId)
            || ! is_int($schemaVersion)
            || ! is_string($interactionDigest)) {
            $request->attributes->set('openclaw.audit.outcome', 'not_found');

            return response()->json(['message' => 'Owner not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $operation = $capability === 'financial.export.prepare'
                ? $this->prepareFinancialExport->handle(
                    owner: $owner,
                    serviceKeyId: $serviceKeyId,
                    schemaVersion: $schemaVersion,
                    conversationId: (string) $interaction['conversation_id'],
                    preparationInteractionDigest: $interactionDigest,
                    preparationOccurredAt: CarbonImmutable::parse((string) $interaction['occurred_at']),
                    idempotencyKey: (string) $input['idempotency_key'],
                )
                : $this->prepareFinancialDeletion->handle(
                    owner: $owner,
                    serviceKeyId: $serviceKeyId,
                    schemaVersion: $schemaVersion,
                    conversationId: (string) $interaction['conversation_id'],
                    preparationInteractionDigest: $interactionDigest,
                    preparationOccurredAt: CarbonImmutable::parse((string) $interaction['occurred_at']),
                    input: $input,
                );
        } catch (IdempotencyKeyConflict) {
            $request->attributes->set('openclaw.audit.outcome', 'idempotency_conflict');

            return response()->json(
                ['message' => 'Idempotency key conflicts with an earlier request.'],
                Response::HTTP_CONFLICT,
            );
        } catch (StaleCategoryRevision|StaleReceiptBreakdownRevision) {
            $request->attributes->set('openclaw.audit.outcome', 'stale_revision');

            return response()->json(
                ['message' => 'The financial resource changed. Prepare the deletion again.'],
                Response::HTTP_CONFLICT,
            );
        } catch (CategoryOperationBlocked|ValidationException $exception) {
            $request->attributes->set('openclaw.audit.outcome', 'invalid_request');

            return response()->json(
                ['message' => $exception->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->prepared($request, $operation);
    }

    private function prepared(
        Request $request,
        OpenClawPendingOperation $operation,
    ): JsonResponse {
        $request->attributes->set('openclaw.audit.outcome', 'success');

        return response()->json([
            'schema_version' => 1,
            'pending_operation' => [
                'id' => $operation->operation_id,
                'revision' => $operation->prepared_revision,
                'expires_at' => $operation->expires_at->toIso8601String(),
                'payload_digest' => $operation->payload_digest,
                'effect_summary' => $operation->effect_summary,
                'web_continuation' => route('high_impact_operations.show', $operation->operation_id),
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Actions\Categorization\ConfirmOpenClawCategorization;
use App\Actions\Categorization\PrepareOpenClawCategorization;
use App\Actions\Categorization\ReadCategoryTaxonomy;
use App\Actions\Ledger\ConfirmOpenClawManualTransaction;
use App\Actions\Ledger\PrepareOpenClawManualTransaction;
use App\Actions\Ledger\ReadTransactionForOpenClaw;
use App\Currency;
use App\Exceptions\CategoryOperationBlocked;
use App\Exceptions\IdempotencyKeyConflict;
use App\Exceptions\OpenClawConfirmationRejected;
use App\Exceptions\StaleCategoryRevision;
use App\Exceptions\StaleTransactionRevision;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class OpenClawTransportController extends Controller
{
    public function __construct(
        private ReadTransactionForOpenClaw $readTransaction,
        private PrepareOpenClawManualTransaction $prepareManualTransaction,
        private ConfirmOpenClawManualTransaction $confirmManualTransaction,
        private ReadCategoryTaxonomy $readCategoryTaxonomy,
        private PrepareOpenClawCategorization $prepareCategorization,
        private ConfirmOpenClawCategorization $confirmCategorization,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $owner = User::query()->first();
        $capability = $request->attributes->get('openclaw.capability');

        if ($capability === 'transaction.manual.prepare') {
            return $this->prepareManualTransaction($request, $owner);
        }

        if ($capability === 'transaction.manual.confirm') {
            return $this->confirmManualTransaction($request, $owner);
        }

        if ($capability === 'category.read') {
            return $this->readCategories($request, $owner);
        }

        if ($capability === 'category.mutation.prepare') {
            return $this->prepareCategorization($request, $owner);
        }

        if ($capability === 'category.mutation.confirm') {
            return $this->confirmCategorization($request, $owner);
        }

        $transactionId = $request->attributes->get('openclaw.transaction_id');
        $transaction = $owner === null || ! is_int($transactionId)
            ? null
            : $this->readTransaction->handle($owner, $transactionId);

        if ($transaction === null) {
            $request->attributes->set('openclaw.audit.outcome', 'not_found');

            return response()->json(
                ['message' => 'Transaction not found.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $request->attributes->set('openclaw.audit.outcome', 'success');
        $request->attributes->set('openclaw.audit.result_count', 1);

        return response()->json([
            'schema_version' => 1,
            'transaction' => $transaction,
        ]);
    }

    private function prepareManualTransaction(Request $request, ?User $owner): JsonResponse
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
            $operation = $this->prepareManualTransaction->handle(
                owner: $owner,
                serviceKeyId: $serviceKeyId,
                schemaVersion: $schemaVersion,
                conversationId: (string) $interaction['conversation_id'],
                preparationInteractionDigest: $interactionDigest,
                preparationOccurredAt: CarbonImmutable::parse((string) $interaction['occurred_at']),
                idempotencyKey: (string) $input['idempotency_key'],
                occurredOn: CarbonImmutable::createFromFormat('!Y-m-d', (string) $input['occurred_on']),
                amountMinor: (int) $input['amount_minor'],
                currency: Currency::from((string) $input['currency']),
                kind: TransactionKind::from((string) $input['kind']),
                merchantDescription: (string) $input['merchant_description'],
            );
        } catch (IdempotencyKeyConflict) {
            $request->attributes->set('openclaw.audit.outcome', 'idempotency_conflict');

            return response()->json(
                ['message' => 'Idempotency key conflicts with an earlier request.'],
                Response::HTTP_CONFLICT,
            );
        }

        $request->attributes->set('openclaw.audit.outcome', 'success');

        return response()->json([
            'schema_version' => 1,
            'pending_operation' => [
                'id' => $operation->operation_id,
                'revision' => $operation->prepared_revision,
                'expires_at' => $operation->expires_at->toIso8601String(),
                'payload_digest' => $operation->payload_digest,
                'effect_summary' => $operation->effect_summary,
            ],
        ]);
    }

    private function confirmManualTransaction(Request $request, ?User $owner): JsonResponse
    {
        $input = $request->attributes->get('openclaw.input');
        $interaction = $request->attributes->get('openclaw.interaction');
        $serviceKeyId = $request->attributes->get('openclaw.key_id');
        $schemaVersion = $request->attributes->get('openclaw.schema_version');
        $interactionDigest = $request->attributes->get('openclaw.interaction_digest');
        $nonce = $request->attributes->get('openclaw.nonce');

        if ($owner === null
            || ! is_array($input)
            || ! is_array($interaction)
            || ! is_string($serviceKeyId)
            || ! is_int($schemaVersion)
            || ! is_string($interactionDigest)
            || ! is_string($nonce)) {
            $request->attributes->set('openclaw.audit.outcome', 'not_found');

            return response()->json(['message' => 'Owner not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $confirmation = $this->confirmManualTransaction->handle(
                owner: $owner,
                serviceKeyId: $serviceKeyId,
                schemaVersion: $schemaVersion,
                conversationId: (string) $interaction['conversation_id'],
                approvalInteractionDigest: $interactionDigest,
                approvalOccurredAt: CarbonImmutable::parse((string) $interaction['occurred_at']),
                pendingOperationId: (string) $input['pending_operation_id'],
                pendingOperationRevision: (int) $input['pending_operation_revision'],
                payloadDigest: (string) $input['payload_digest'],
                idempotencyKey: (string) $input['idempotency_key'],
                nonceDigest: hash('sha256', $nonce),
                requestDigest: hash('sha256', $request->getContent()),
            );
        } catch (IdempotencyKeyConflict) {
            $request->attributes->set('openclaw.audit.outcome', 'idempotency_conflict');

            return response()->json(
                ['message' => 'Idempotency key conflicts with an earlier request.'],
                Response::HTTP_CONFLICT,
            );
        } catch (OpenClawConfirmationRejected $exception) {
            $request->attributes->set('openclaw.audit.outcome', $exception->outcome);

            return response()->json(
                ['message' => 'Confirmation request rejected.'],
                $exception->httpStatus,
            );
        }

        $request->attributes->set(
            'openclaw.audit.outcome',
            $confirmation['replayed'] ? 'idempotent_replay' : 'success',
        );
        $request->attributes->set('openclaw.audit.result_count', 1);

        if (! $confirmation['replayed']) {
            $request->attributes->set('openclaw.audit.recorded', true);
        }

        return response()->json([
            'schema_version' => 1,
            'transaction' => $confirmation['transaction'],
        ]);
    }

    private function readCategories(Request $request, ?User $owner): JsonResponse
    {
        $input = $request->attributes->get('openclaw.input');

        if ($owner === null || ! is_array($input)) {
            $request->attributes->set('openclaw.audit.outcome', 'not_found');

            return response()->json(['message' => 'Owner not found.'], Response::HTTP_NOT_FOUND);
        }

        $request->attributes->set('openclaw.audit.outcome', 'success');
        $request->attributes->set('openclaw.audit.resource_type', 'category_taxonomy');
        $request->attributes->set('openclaw.audit.result_count', 1);

        return response()->json([
            'schema_version' => 1,
            ...$this->readCategoryTaxonomy->forOpenClaw(
                $owner,
                (int) $input['page'],
                (int) $input['per_page'],
            ),
        ]);
    }

    private function prepareCategorization(Request $request, ?User $owner): JsonResponse
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
            $operation = $this->prepareCategorization->handle(
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
        } catch (StaleCategoryRevision|StaleTransactionRevision) {
            $request->attributes->set('openclaw.audit.outcome', 'stale_revision');

            return response()->json(['message' => 'Referenced state changed.'], Response::HTTP_CONFLICT);
        } catch (CategoryOperationBlocked|ValidationException) {
            $request->attributes->set('openclaw.audit.outcome', 'invalid_request');

            return response()->json(['message' => 'Categorization operation is not valid.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ModelNotFoundException) {
            $request->attributes->set('openclaw.audit.outcome', 'not_found');

            return response()->json(['message' => 'Referenced resource not found.'], Response::HTTP_NOT_FOUND);
        }

        $request->attributes->set('openclaw.audit.outcome', 'success');
        $request->attributes->set('openclaw.audit.resource_type', 'category');

        return response()->json([
            'schema_version' => 1,
            'pending_operation' => [
                'id' => $operation->operation_id,
                'revision' => $operation->prepared_revision,
                'expires_at' => $operation->expires_at->toIso8601String(),
                'payload_digest' => $operation->payload_digest,
                'effect_summary' => $operation->effect_summary,
            ],
        ]);
    }

    private function confirmCategorization(Request $request, ?User $owner): JsonResponse
    {
        $input = $request->attributes->get('openclaw.input');
        $interaction = $request->attributes->get('openclaw.interaction');
        $serviceKeyId = $request->attributes->get('openclaw.key_id');
        $schemaVersion = $request->attributes->get('openclaw.schema_version');
        $interactionDigest = $request->attributes->get('openclaw.interaction_digest');
        $nonce = $request->attributes->get('openclaw.nonce');

        if ($owner === null
            || ! is_array($input)
            || ! is_array($interaction)
            || ! is_string($serviceKeyId)
            || ! is_int($schemaVersion)
            || ! is_string($interactionDigest)
            || ! is_string($nonce)) {
            $request->attributes->set('openclaw.audit.outcome', 'not_found');

            return response()->json(['message' => 'Owner not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $confirmation = $this->confirmCategorization->handle(
                owner: $owner,
                serviceKeyId: $serviceKeyId,
                schemaVersion: $schemaVersion,
                conversationId: (string) $interaction['conversation_id'],
                approvalInteractionDigest: $interactionDigest,
                approvalOccurredAt: CarbonImmutable::parse((string) $interaction['occurred_at']),
                pendingOperationId: (string) $input['pending_operation_id'],
                pendingOperationRevision: (int) $input['pending_operation_revision'],
                payloadDigest: (string) $input['payload_digest'],
                idempotencyKey: (string) $input['idempotency_key'],
                nonceDigest: hash('sha256', $nonce),
                requestDigest: hash('sha256', $request->getContent()),
            );
        } catch (IdempotencyKeyConflict) {
            $request->attributes->set('openclaw.audit.outcome', 'idempotency_conflict');

            return response()->json(
                ['message' => 'Idempotency key conflicts with an earlier request.'],
                Response::HTTP_CONFLICT,
            );
        } catch (OpenClawConfirmationRejected $exception) {
            $request->attributes->set('openclaw.audit.outcome', $exception->outcome);

            return response()->json(['message' => 'Confirmation request rejected.'], $exception->httpStatus);
        } catch (StaleCategoryRevision|StaleTransactionRevision) {
            $request->attributes->set('openclaw.audit.outcome', 'stale_revision');

            return response()->json(['message' => 'Referenced state changed.'], Response::HTTP_CONFLICT);
        } catch (CategoryOperationBlocked|ValidationException) {
            $request->attributes->set('openclaw.audit.outcome', 'invalid_request');

            return response()->json(['message' => 'Categorization operation is not valid.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ModelNotFoundException) {
            $request->attributes->set('openclaw.audit.outcome', 'not_found');

            return response()->json(['message' => 'Referenced resource not found.'], Response::HTTP_NOT_FOUND);
        }

        $request->attributes->set(
            'openclaw.audit.outcome',
            $confirmation['replayed'] ? 'idempotent_replay' : 'success',
        );
        $request->attributes->set('openclaw.audit.result_count', 1);

        if (! $confirmation['replayed']) {
            $request->attributes->set('openclaw.audit.recorded', true);
        }

        return response()->json([
            'schema_version' => 1,
            'mutation' => $confirmation['mutation'],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Actions\Categorization\ConfirmOpenClawCategorization;
use App\Actions\Categorization\PrepareOpenClawCategorization;
use App\Actions\Categorization\ReadCategoryTaxonomy;
use App\Actions\Ledger\ConfirmOpenClawManualTransaction;
use App\Actions\Ledger\PrepareOpenClawManualTransaction;
use App\Actions\Ledger\ReadTransactionForOpenClaw;
use App\Actions\ReceiptReconciliation\ConfirmOpenClawReceiptBreakdown;
use App\Actions\ReceiptReconciliation\PrepareOpenClawReceiptBreakdown;
use App\Actions\ReceiptReconciliation\SubmitReceiptProposal;
use App\Actions\Reminders\ReadReminderForOpenClaw;
use App\Actions\Reminders\RecordReminderChannelDelivery;
use App\Actions\Reminders\RespondToReminder;
use App\Currency;
use App\Exceptions\CategoryOperationBlocked;
use App\Exceptions\IdempotencyKeyConflict;
use App\Exceptions\OpenClawConfirmationRejected;
use App\Exceptions\ReceiptBreakdownNotReconciled;
use App\Exceptions\StaleCategoryRevision;
use App\Exceptions\StaleReceiptBreakdownRevision;
use App\Exceptions\StaleTransactionRevision;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
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
        private ReadReminderForOpenClaw $readReminder,
        private RecordReminderChannelDelivery $recordReminderChannelDelivery,
        private RespondToReminder $respondToReminder,
        private SubmitReceiptProposal $submitReceiptProposal,
        private PrepareOpenClawReceiptBreakdown $prepareReceiptBreakdown,
        private ConfirmOpenClawReceiptBreakdown $confirmReceiptBreakdown,
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

        if ($capability === 'reminder.read') {
            return $this->readReminder($request, $owner);
        }

        if ($capability === 'reminder.delivery.record') {
            return $this->recordReminderChannelDelivery($request, $owner);
        }

        if ($capability === 'reminder.respond') {
            return $this->respondToReminder($request, $owner);
        }

        if ($capability === 'receipt.proposal.submit') {
            return $this->submitReceiptProposal($request, $owner);
        }

        if ($capability === 'receipt.breakdown.mutation.prepare') {
            return $this->prepareReceiptBreakdown($request, $owner);
        }

        if ($capability === 'receipt.breakdown.mutation.confirm') {
            return $this->confirmReceiptBreakdown($request, $owner);
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

    private function readReminder(Request $request, ?User $owner): JsonResponse
    {
        $input = $request->attributes->get('openclaw.input');
        $eventId = is_array($input) ? ($input['event_id'] ?? null) : null;
        $result = $owner !== null && is_string($eventId)
            ? $this->readReminder->handle($owner, $eventId)
            : null;

        if ($result === null) {
            $request->attributes->set('openclaw.audit.outcome', 'not_found');

            return response()->json(['message' => 'Reminder not found.'], Response::HTTP_NOT_FOUND);
        }

        $request->attributes->set('openclaw.audit.outcome', 'success');
        $request->attributes->set('openclaw.audit.resource_type', 'reminder');
        $request->attributes->set('openclaw.audit.result_count', 1);

        return response()->json([
            'schema_version' => 1,
            ...$result,
        ]);
    }

    private function recordReminderChannelDelivery(Request $request, ?User $owner): JsonResponse
    {
        $input = $request->attributes->get('openclaw.input');
        $eventId = is_array($input) ? ($input['event_id'] ?? null) : null;
        $serviceKeyId = $request->attributes->get('openclaw.key_id');
        $schemaVersion = $request->attributes->get('openclaw.schema_version');
        $interactionDigest = $request->attributes->get('openclaw.interaction_digest');
        $nonce = $request->attributes->get('openclaw.nonce');
        $result = $owner !== null
            && is_string($eventId)
            && is_string($serviceKeyId)
            && is_int($schemaVersion)
            && is_string($interactionDigest)
            && is_string($nonce)
            ? $this->recordReminderChannelDelivery->handle(
                owner: $owner,
                eventId: $eventId,
                serviceKeyId: $serviceKeyId,
                schemaVersion: $schemaVersion,
                interactionDigest: $interactionDigest,
                nonceDigest: hash('sha256', $nonce),
                requestDigest: hash('sha256', $request->getContent()),
            )
            : null;

        if ($result === null) {
            $request->attributes->set('openclaw.audit.outcome', 'not_found');

            return response()->json(['message' => 'Reminder delivery not found.'], Response::HTTP_NOT_FOUND);
        }

        $request->attributes->set(
            'openclaw.audit.outcome',
            $result['replayed'] ? 'idempotent_replay' : 'success',
        );
        $request->attributes->set('openclaw.audit.resource_type', 'reminder');
        $request->attributes->set('openclaw.audit.result_count', 1);
        $request->attributes->set('openclaw.audit.recorded', true);

        return response()->json([
            'schema_version' => 1,
            'delivery' => [
                'event_id' => $result['delivery']->id,
                'hook_accepted_at' => $result['delivery']->accepted_at?->toIso8601String(),
                'channel_delivered_at' => $result['delivery']->delivered_at?->toIso8601String(),
            ],
        ]);
    }

    private function respondToReminder(Request $request, ?User $owner): JsonResponse
    {
        $input = $request->attributes->get('openclaw.input');
        $serviceKeyId = $request->attributes->get('openclaw.key_id');
        $schemaVersion = $request->attributes->get('openclaw.schema_version');
        $interactionDigest = $request->attributes->get('openclaw.interaction_digest');
        $nonce = $request->attributes->get('openclaw.nonce');

        if ($owner === null
            || ! is_array($input)
            || ! is_string($serviceKeyId)
            || ! is_int($schemaVersion)
            || ! is_string($interactionDigest)
            || ! is_string($nonce)) {
            $request->attributes->set('openclaw.audit.outcome', 'not_found');

            return response()->json(['message' => 'Reminder not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->respondToReminder->handle(
                owner: $owner,
                serviceKeyId: $serviceKeyId,
                schemaVersion: $schemaVersion,
                interactionDigest: $interactionDigest,
                nonceDigest: hash('sha256', $nonce),
                requestDigest: hash('sha256', $request->getContent()),
                idempotencyKey: (string) $input['idempotency_key'],
                reminderId: (int) $input['reminder_id'],
                action: (string) $input['action'],
                snoozedUntil: isset($input['snoozed_until'])
                    ? CarbonImmutable::parse((string) $input['snoozed_until'])
                    : null,
            );
        } catch (IdempotencyKeyConflict) {
            $request->attributes->set('openclaw.audit.outcome', 'idempotency_conflict');

            return response()->json(
                ['message' => 'Idempotency key conflicts with an earlier response.'],
                Response::HTTP_CONFLICT,
            );
        } catch (ModelNotFoundException) {
            $request->attributes->set('openclaw.audit.outcome', 'not_found');

            return response()->json(['message' => 'Reminder not found.'], Response::HTTP_NOT_FOUND);
        } catch (InvalidArgumentException) {
            $request->attributes->set('openclaw.audit.outcome', 'invalid_request');

            return response()->json(['message' => 'Reminder response rejected.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $request->attributes->set(
            'openclaw.audit.outcome',
            $result['replayed'] ? 'idempotent_replay' : 'success',
        );
        $request->attributes->set('openclaw.audit.resource_type', 'reminder');
        $request->attributes->set('openclaw.audit.result_count', 1);
        $request->attributes->set('openclaw.audit.recorded', true);

        return response()->json([
            'schema_version' => 1,
            'reminder' => $this->readReminder->state($result['reminder']),
        ]);
    }

    private function submitReceiptProposal(Request $request, ?User $owner): JsonResponse
    {
        $input = $request->attributes->get('openclaw.input');
        $serviceKeyId = $request->attributes->get('openclaw.key_id');
        $schemaVersion = $request->attributes->get('openclaw.schema_version');
        $interactionDigest = $request->attributes->get('openclaw.interaction_digest');
        $nonce = $request->attributes->get('openclaw.nonce');

        if ($owner === null
            || ! is_array($input)
            || ! is_array($input['transaction'] ?? null)
            || ! is_array($input['line_items'] ?? null)
            || ! is_string($serviceKeyId)
            || ! is_int($schemaVersion)
            || ! is_string($interactionDigest)
            || ! is_string($nonce)) {
            $request->attributes->set('openclaw.audit.outcome', 'not_found');

            return response()->json(['message' => 'Owner not found.'], Response::HTTP_NOT_FOUND);
        }

        $transaction = $input['transaction'];

        if (! is_string($transaction['occurred_on'] ?? null)
            || ! is_int($transaction['amount_minor'] ?? null)
            || ! is_string($transaction['currency'] ?? null)
            || ! is_string($transaction['kind'] ?? null)
            || ! is_string($transaction['merchant_description'] ?? null)) {
            $request->attributes->set('openclaw.audit.outcome', 'invalid_request');

            return response()->json(['message' => 'Receipt Proposal is invalid.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $proposedTransaction = [
            'occurred_on' => $transaction['occurred_on'],
            'amount_minor' => $transaction['amount_minor'],
            'currency' => $transaction['currency'],
            'kind' => $transaction['kind'],
            'merchant_description' => $transaction['merchant_description'],
        ];
        $proposedLineItems = [];

        foreach ($input['line_items'] as $lineItem) {
            if (! is_array($lineItem)
                || ! is_string($lineItem['description'] ?? null)
                || ! is_int($lineItem['line_total_minor'] ?? null)) {
                $request->attributes->set('openclaw.audit.outcome', 'invalid_request');

                return response()->json(['message' => 'Receipt Proposal is invalid.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $proposedLineItem = [
                'description' => $lineItem['description'],
                'line_total_minor' => $lineItem['line_total_minor'],
            ];

            if ($input['contract_version'] === 2) {
                $proposedLineItem = [
                    'description' => $lineItem['description'],
                    'role' => $lineItem['role'],
                    'quantity' => $lineItem['quantity'],
                    'unit_price_minor' => $lineItem['unit_price_minor'],
                    'line_total_minor' => $lineItem['line_total_minor'],
                ];
            }

            $proposedLineItems[] = $proposedLineItem;
        }

        try {
            $result = $this->submitReceiptProposal->handle(
                owner: $owner,
                serviceKeyId: $serviceKeyId,
                schemaVersion: $schemaVersion,
                nonceDigest: hash('sha256', $nonce),
                requestDigest: hash('sha256', $request->getContent()),
                interactionDigest: $interactionDigest,
                proposalId: (string) $input['proposal_id'],
                sourceKind: (string) $input['source_kind'],
                processedAt: CarbonImmutable::parse((string) $input['processed_at']),
                provider: (string) $input['provider'],
                model: (string) $input['model'],
                contractVersion: (int) $input['contract_version'],
                proposedTransaction: $proposedTransaction,
                proposedLineItems: $proposedLineItems,
            );
        } catch (IdempotencyKeyConflict) {
            $request->attributes->set('openclaw.audit.outcome', 'idempotency_conflict');

            return response()->json(
                ['message' => 'Proposal identifier conflicts with an earlier proposal.'],
                Response::HTTP_CONFLICT,
            );
        }

        $request->attributes->set(
            'openclaw.audit.outcome',
            $result['replayed'] ? 'idempotent_replay' : 'success',
        );
        $request->attributes->set('openclaw.audit.resource_type', 'receipt_proposal');
        $request->attributes->set('openclaw.audit.result_count', 1);
        $request->attributes->set('openclaw.audit.recorded', true);

        return response()->json([
            'schema_version' => 1,
            'receipt_proposal' => [
                'id' => $result['proposal']->proposal_id,
                'status' => 'accepted',
            ],
        ]);
    }

    private function prepareReceiptBreakdown(Request $request, ?User $owner): JsonResponse
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
            $operation = $this->prepareReceiptBreakdown->handle(
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

            return response()->json(['message' => 'Idempotency key conflicts with an earlier request.'], Response::HTTP_CONFLICT);
        } catch (StaleReceiptBreakdownRevision|StaleTransactionRevision) {
            $request->attributes->set('openclaw.audit.outcome', 'stale_revision');

            return response()->json(['message' => 'Receipt Breakdown draft changed.'], Response::HTTP_CONFLICT);
        } catch (ValidationException|InvalidArgumentException) {
            $request->attributes->set('openclaw.audit.outcome', 'invalid_request');

            return response()->json(['message' => 'Receipt Breakdown operation is not valid.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ModelNotFoundException) {
            $request->attributes->set('openclaw.audit.outcome', 'not_found');

            return response()->json(['message' => 'Receipt Breakdown not found.'], Response::HTTP_NOT_FOUND);
        }

        $request->attributes->set('openclaw.audit.outcome', 'success');
        $request->attributes->set('openclaw.audit.resource_type', 'receipt_breakdown');

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

    private function confirmReceiptBreakdown(Request $request, ?User $owner): JsonResponse
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
            $confirmation = $this->confirmReceiptBreakdown->handle(
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

            return response()->json(['message' => 'Idempotency key conflicts with an earlier request.'], Response::HTTP_CONFLICT);
        } catch (OpenClawConfirmationRejected $exception) {
            $request->attributes->set('openclaw.audit.outcome', $exception->outcome);

            return response()->json(['message' => 'Confirmation request rejected.'], $exception->httpStatus);
        } catch (StaleReceiptBreakdownRevision|StaleTransactionRevision) {
            $request->attributes->set('openclaw.audit.outcome', 'stale_revision');

            return response()->json(['message' => 'Receipt Breakdown draft changed.'], Response::HTTP_CONFLICT);
        } catch (ReceiptBreakdownNotReconciled|ValidationException) {
            $request->attributes->set('openclaw.audit.outcome', 'invalid_request');

            return response()->json(['message' => 'Receipt Breakdown operation is not valid.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ModelNotFoundException) {
            $request->attributes->set('openclaw.audit.outcome', 'not_found');

            return response()->json(['message' => 'Receipt Breakdown not found.'], Response::HTTP_NOT_FOUND);
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

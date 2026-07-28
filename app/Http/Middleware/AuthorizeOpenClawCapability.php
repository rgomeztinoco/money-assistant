<?php

namespace App\Http\Middleware;

use App\Currency;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AuthorizeOpenClawCapability
{
    private const int MAX_SAFE_INTEGER = 9_007_199_254_740_991;

    private const int MAXIMUM_CLOCK_SKEW_IN_SECONDS = 300;

    private const int MAXIMUM_INTERACTION_AGE_IN_SECONDS = 1800;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = $request->attributes->get('openclaw.timestamp');
        $nonce = $request->attributes->get('openclaw.nonce');
        $keyId = $request->attributes->get('openclaw.key_id');

        if (! is_string($timestamp)
            || ! ctype_digit($timestamp)
            || abs(now()->getTimestamp() - (int) $timestamp) > self::MAXIMUM_CLOCK_SKEW_IN_SECONDS) {
            return $this->reject($request, 'stale_signature', Response::HTTP_UNAUTHORIZED);
        }

        if (! is_string($nonce)
            || ! is_string($keyId)
            || preg_match('/^[a-zA-Z0-9_-]{16,128}$/', $nonce) !== 1) {
            return $this->reject($request, 'invalid_request', Response::HTTP_UNAUTHORIZED);
        }

        try {
            DB::transaction(fn () => DB::table('open_claw_request_nonces')->insert([
                'key_id' => $keyId,
                'nonce_digest' => hash('sha256', $keyId."\0".$nonce),
                'expires_at' => CarbonImmutable::createFromTimestampUTC((int) $timestamp)
                    ->addSeconds(self::MAXIMUM_CLOCK_SKEW_IN_SECONDS + 1),
                'created_at' => now(),
            ]));
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            return $this->reject($request, 'replayed_nonce', Response::HTTP_CONFLICT);
        }

        if ($request->query->count() !== 0) {
            return $this->reject($request, 'invalid_request');
        }

        try {
            $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->reject($request, 'invalid_request');
        }

        if (! is_array($payload)) {
            return $this->reject($request, 'invalid_request');
        }

        if (($payload['schema_version'] ?? null) !== 1) {
            return $this->reject($request, 'unsupported_schema');
        }

        $capability = $payload['capability'] ?? null;

        if (! is_string($capability) || ! in_array($capability, [
            'transaction.read',
            'transaction.manual.prepare',
            'transaction.manual.confirm',
            'category.read',
            'category.mutation.prepare',
            'category.mutation.confirm',
            'receipt.proposal.submit',
            'receipt.breakdown.mutation.prepare',
            'receipt.breakdown.mutation.confirm',
            'reminder.read',
            'reminder.delivery.record',
            'reminder.respond',
        ], true)) {
            return $this->reject($request, 'unsupported_capability');
        }

        $isReminderEventCapability = in_array(
            $capability,
            ['reminder.read', 'reminder.delivery.record'],
            true,
        );

        $topLevelKeys = array_keys($payload);
        sort($topLevelKeys);

        if ($topLevelKeys !== ['capability', 'input', 'interaction', 'schema_version']) {
            return $this->reject($request, 'invalid_request');
        }

        $rules = [
            'schema_version' => ['required', 'integer'],
            'capability' => ['required', 'string'],
            'interaction' => ['required', 'array:kind,agent_id,account_id,conversation_id,owner_sender_id,message_id,occurred_at'],
            'interaction.kind' => ['required', 'string'],
            'interaction.agent_id' => ['required', 'string', 'max:128'],
            'interaction.account_id' => ['required', 'string', 'max:128'],
            'interaction.conversation_id' => ['required', 'string', 'max:128'],
            'interaction.owner_sender_id' => ['required', 'string', 'max:128'],
            'interaction.message_id' => ['required', 'string', 'max:255'],
            'interaction.occurred_at' => ['required', 'string', 'max:35'],
        ];

        if ($isReminderEventCapability) {
            $rules += [
                'input' => ['required', 'array:event_id'],
                'input.event_id' => ['required', 'uuid'],
            ];
        } elseif ($capability === 'reminder.respond') {
            $rules += [
                'input' => ['required', 'array'],
                'input.idempotency_key' => ['required', 'uuid'],
                'input.reminder_id' => ['required', 'integer', 'min:1'],
                'input.action' => ['required', 'string', Rule::in(['acknowledge', 'snooze', 'dismiss'])],
                'input.snoozed_until' => ['nullable', 'string', 'max:35'],
            ];
        } elseif ($capability === 'transaction.read') {
            $rules += [
                'input' => ['required', 'array:transaction_id'],
                'input.transaction_id' => ['required', 'integer', 'min:1'],
            ];
        } elseif ($capability === 'category.read') {
            $rules += [
                'input' => ['required', 'array:page,per_page'],
                'input.page' => ['required', 'integer', 'min:1'],
                'input.per_page' => ['required', 'integer', 'min:1', 'max:100'],
            ];
        } elseif ($capability === 'transaction.manual.prepare') {
            $rules += [
                'input' => ['required', 'array:idempotency_key,occurred_on,amount_minor,currency,kind,merchant_description'],
                'input.idempotency_key' => ['required', 'uuid'],
                'input.occurred_on' => ['required', 'date_format:Y-m-d'],
                'input.amount_minor' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
                'input.currency' => ['required', Rule::enum(Currency::class)],
                'input.kind' => ['required', Rule::enum(TransactionKind::class)],
                'input.merchant_description' => ['required', 'string', 'max:255'],
            ];
        } elseif ($capability === 'receipt.proposal.submit') {
            $rules += [
                'input' => ['required', 'array:proposal_id,source_kind,processed_at,provider,model,contract_version,transaction,line_items'],
                'input.proposal_id' => ['required', 'uuid'],
                'input.source_kind' => ['required', 'string', Rule::in(['receipt_photo'])],
                'input.processed_at' => ['required', 'string', 'max:35'],
                'input.provider' => ['required', 'string', Rule::in(['openai'])],
                'input.model' => ['required', 'string', Rule::in(['openai/gpt-5.6'])],
                'input.contract_version' => ['required', 'integer', Rule::in([1])],
                'input.transaction' => ['required', 'array:occurred_on,amount_minor,currency,kind,merchant_description'],
                'input.transaction.occurred_on' => ['required', 'date_format:Y-m-d'],
                'input.transaction.amount_minor' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
                'input.transaction.currency' => ['required', Rule::enum(Currency::class)],
                'input.transaction.kind' => ['required', Rule::enum(TransactionKind::class)],
                'input.transaction.merchant_description' => ['required', 'string', 'max:255'],
                'input.line_items' => ['required', 'array', 'min:1', 'max:200'],
                'input.line_items.*' => ['required', 'array:description,line_total_minor'],
                'input.line_items.*.description' => ['required', 'string', 'max:255'],
                'input.line_items.*.line_total_minor' => ['required', 'integer', 'not_in:0'],
            ];
        } elseif ($capability === 'category.mutation.prepare') {
            $rules += [
                'input' => ['required', 'array'],
                'input.idempotency_key' => ['required', 'uuid'],
                'input.operation' => ['required', 'string'],
            ];
        } elseif ($capability === 'receipt.breakdown.mutation.prepare') {
            $rules += [
                'input' => ['required', 'array'],
                'input.idempotency_key' => ['required', 'uuid'],
                'input.operation' => ['required', 'string', Rule::in(['update_draft', 'confirm_draft'])],
                'input.receipt_breakdown_id' => ['required', 'integer', 'min:1', 'max:'.self::MAX_SAFE_INTEGER],
                'input.expected_revision' => ['required', 'integer', 'min:1', 'max:'.self::MAX_SAFE_INTEGER],
            ];
        } else {
            $rules += [
                'input' => ['required', 'array:idempotency_key,pending_operation_id,pending_operation_revision,payload_digest'],
                'input.idempotency_key' => ['required', 'uuid'],
                'input.pending_operation_id' => ['required', 'uuid'],
                'input.pending_operation_revision' => ['required', 'integer', 'min:1'],
                'input.payload_digest' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            ];
        }

        $validator = Validator::make($payload, $rules);

        if ($validator->fails()) {
            return $this->reject(
                $request,
                $capability === 'transaction.read' ? 'unbound_interaction' : 'invalid_request',
            );
        }

        $rejection = $this->authorizeValidatedPayload($request, $payload, $capability);

        if ($rejection !== null) {
            return $rejection;
        }

        return $next($request);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function authorizeValidatedPayload(
        Request $request,
        array $payload,
        string $capability,
    ): ?JsonResponse {
        if ($capability === 'category.mutation.prepare'
            && ! $this->hasValidCategoryMutationInput($payload['input'] ?? null)) {
            return $this->reject($request, 'invalid_request');
        }

        if ($capability === 'receipt.breakdown.mutation.prepare'
            && ! $this->hasValidReceiptBreakdownMutationInput($payload['input'] ?? null)) {
            return $this->reject($request, 'invalid_request');
        }

        if ($capability === 'reminder.respond'
            && ! $this->hasValidReminderResponseInput($payload['input'] ?? null)) {
            return $this->reject($request, 'invalid_request');
        }

        if ($capability === 'transaction.manual.prepare'
            && ! is_int($payload['input']['amount_minor'] ?? null)) {
            return $this->reject($request, 'invalid_request');
        }

        if ($capability === 'receipt.proposal.submit'
            && ! $this->hasValidReceiptProposalInput($payload['input'] ?? null)) {
            return $this->reject($request, 'invalid_request');
        }

        if ($capability === 'category.read'
            && (! is_int($payload['input']['page'] ?? null)
                || ! is_int($payload['input']['per_page'] ?? null))) {
            return $this->reject($request, 'invalid_request');
        }

        if (in_array($capability, [
            'transaction.manual.confirm',
            'category.mutation.confirm',
            'receipt.breakdown.mutation.confirm',
        ], true)
            && ! is_int($payload['input']['pending_operation_revision'] ?? null)) {
            return $this->reject($request, 'invalid_request');
        }

        $isReminderEventCapability = in_array(
            $capability,
            ['reminder.read', 'reminder.delivery.record'],
            true,
        );

        if ($isReminderEventCapability
            && (($payload['interaction']['kind'] ?? null) !== 'money_assistant_event'
                || ! is_string($payload['input']['event_id'] ?? null)
                || ! hash_equals(
                    $payload['input']['event_id'],
                    (string) ($payload['interaction']['message_id'] ?? ''),
                ))) {
            return $this->reject($request, 'unbound_interaction');
        }

        if (! $this->hasExpectedInteractionBinding(
            $payload['interaction'] ?? null,
            match (true) {
                $isReminderEventCapability => 'money_assistant_event',
                $capability === 'receipt.proposal.submit' => 'owner_photo_message',
                default => 'owner_message',
            },
        )
            || ! $this->hasFreshInteraction($payload['interaction']['occurred_at'] ?? null)) {
            return $this->reject($request, 'unbound_interaction');
        }

        if ($capability === 'transaction.read') {
            if (! is_int($payload['input']['transaction_id'] ?? null)) {
                return $this->reject($request, 'unbound_interaction');
            }

            $request->attributes->set(
                'openclaw.transaction_id',
                $payload['input']['transaction_id'],
            );
        }

        $request->attributes->set('openclaw.capability', $capability);
        $request->attributes->set('openclaw.schema_version', $payload['schema_version']);
        $request->attributes->set('openclaw.input', $payload['input']);
        $request->attributes->set('openclaw.interaction', $payload['interaction']);
        $request->attributes->set(
            'openclaw.interaction_digest',
            hash('sha256', json_encode([
                'kind' => $payload['interaction']['kind'],
                'agent_id' => $payload['interaction']['agent_id'],
                'account_id' => $payload['interaction']['account_id'],
                'conversation_id' => $payload['interaction']['conversation_id'],
                'owner_sender_id' => $payload['interaction']['owner_sender_id'],
                'message_id' => $payload['interaction']['message_id'],
            ], JSON_THROW_ON_ERROR)),
        );

        return null;
    }

    private function reject(
        Request $request,
        string $outcome,
        int $status = Response::HTTP_UNPROCESSABLE_ENTITY,
    ): JsonResponse {
        $request->attributes->set('openclaw.audit.outcome', $outcome);

        return response()->json(['message' => 'Capability request rejected.'], $status);
    }

    private function hasExpectedInteractionBinding(mixed $interaction, string $expectedKind): bool
    {
        if (! is_array($interaction) || ($interaction['kind'] ?? null) !== $expectedKind) {
            return false;
        }

        foreach ([
            'agent_id' => 'agent_id',
            'account_id' => 'account_id',
            'conversation_id' => 'conversation_id',
            'owner_sender_id' => 'owner_sender_id',
        ] as $payloadKey => $configurationKey) {
            $actual = $interaction[$payloadKey] ?? null;
            $expected = config("services.openclaw.capability.{$configurationKey}");

            if (! is_string($actual)
                || ! is_string($expected)
                || $expected === ''
                || ! hash_equals($expected, $actual)) {
                return false;
            }
        }

        return true;
    }

    private function hasValidCategoryMutationInput(mixed $input): bool
    {
        if (! is_array($input)
            || ! is_string($input['idempotency_key'] ?? null)
            || ! Str::isUuid($input['idempotency_key'])
            || ! is_string($input['operation'] ?? null)) {
            return false;
        }

        $operation = $input['operation'];
        $expectedKeys = match ($operation) {
            'create' => ['description', 'examples', 'idempotency_key', 'name', 'operation', 'parent_id'],
            'update' => ['category_id', 'description', 'examples', 'expected_revision', 'idempotency_key', 'name', 'operation', 'parent_id'],
            'retire', 'reactivate' => ['category_id', 'expected_revision', 'idempotency_key', 'operation'],
            'assign_transaction' => ['category_id', 'expected_revision', 'idempotency_key', 'operation', 'transaction_id'],
            default => null,
        };

        if ($expectedKeys === null) {
            return false;
        }

        $actualKeys = array_keys($input);
        sort($actualKeys);

        if ($actualKeys !== $expectedKeys) {
            return false;
        }

        if (in_array($operation, ['update', 'retire', 'reactivate'], true)
            && (! is_int($input['category_id']) || $input['category_id'] < 1)) {
            return false;
        }

        if (in_array($operation, ['update', 'retire', 'reactivate', 'assign_transaction'], true)
            && (! is_int($input['expected_revision']) || $input['expected_revision'] < 1)) {
            return false;
        }

        if ($operation === 'assign_transaction') {
            return is_int($input['transaction_id'])
                && $input['transaction_id'] > 0
                && ($input['category_id'] === null
                    || (is_int($input['category_id']) && $input['category_id'] > 0));
        }

        if (! in_array($operation, ['create', 'update'], true)) {
            return true;
        }

        return is_string($input['name'])
            && Str::squish($input['name']) !== ''
            && mb_strlen($input['name']) <= 255
            && ($input['parent_id'] === null
                || (is_int($input['parent_id']) && $input['parent_id'] > 0))
            && ($input['description'] === null
                || (is_string($input['description']) && mb_strlen($input['description']) <= 2000))
            && is_array($input['examples'])
            && array_is_list($input['examples'])
            && count($input['examples']) <= 20
            && collect($input['examples'])->every(
                fn (mixed $example): bool => is_string($example) && mb_strlen($example) <= 100,
            );
    }

    private function hasValidReminderResponseInput(mixed $input): bool
    {
        if (! is_array($input)
            || ! is_int($input['reminder_id'] ?? null)
            || $input['reminder_id'] < 1
            || ! is_string($input['action'] ?? null)) {
            return false;
        }

        $expectedKeys = $input['action'] === 'snooze'
            ? ['action', 'idempotency_key', 'reminder_id', 'snoozed_until']
            : ['action', 'idempotency_key', 'reminder_id'];
        $actualKeys = array_keys($input);
        sort($actualKeys);

        if ($actualKeys !== $expectedKeys) {
            return false;
        }

        if ($input['action'] !== 'snooze') {
            return true;
        }

        return is_string($input['snoozed_until'] ?? null)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $input['snoozed_until']) === 1;
    }

    private function hasValidReceiptBreakdownMutationInput(mixed $input): bool
    {
        if (! is_array($input)
            || ! is_string($input['idempotency_key'] ?? null)
            || ! Str::isUuid($input['idempotency_key'])
            || ! is_string($input['operation'] ?? null)
            || ! is_int($input['receipt_breakdown_id'] ?? null)
            || $input['receipt_breakdown_id'] < 1
            || $input['receipt_breakdown_id'] > self::MAX_SAFE_INTEGER
            || ! is_int($input['expected_revision'] ?? null)
            || $input['expected_revision'] < 1
            || $input['expected_revision'] > self::MAX_SAFE_INTEGER) {
            return false;
        }

        $expectedKeys = $input['operation'] === 'update_draft'
            ? ['expected_revision', 'idempotency_key', 'line_items', 'operation', 'receipt_breakdown_id']
            : ['expected_revision', 'idempotency_key', 'operation', 'receipt_breakdown_id'];
        $actualKeys = array_keys($input);
        sort($actualKeys);

        if ($actualKeys !== $expectedKeys) {
            return false;
        }

        if ($input['operation'] === 'confirm_draft') {
            return true;
        }

        if ($input['operation'] !== 'update_draft'
            || ! is_array($input['line_items'] ?? null)
            || ! array_is_list($input['line_items'])
            || count($input['line_items']) < 1
            || count($input['line_items']) > 200) {
            return false;
        }

        $lineItemIds = [];

        foreach ($input['line_items'] as $lineItem) {
            if (! is_array($lineItem)) {
                return false;
            }

            $lineItemKeys = array_keys($lineItem);
            sort($lineItemKeys);

            if ($lineItemKeys !== ['category_id', 'description', 'id', 'line_total_minor']
                || (! is_string($lineItem['id'] ?? null) && ($lineItem['id'] ?? null) !== null)
                || (is_string($lineItem['id']) && ! Str::isUuid($lineItem['id']))
                || (is_string($lineItem['id']) && isset($lineItemIds[$lineItem['id']]))
                || ! is_string($lineItem['description'] ?? null)
                || Str::squish($lineItem['description']) === ''
                || mb_strlen($lineItem['description']) > 255
                || ! is_int($lineItem['line_total_minor'] ?? null)
                || $lineItem['line_total_minor'] < 1
                || $lineItem['line_total_minor'] > self::MAX_SAFE_INTEGER
                || ($lineItem['category_id'] !== null
                    && (! is_int($lineItem['category_id'])
                        || $lineItem['category_id'] < 1
                        || $lineItem['category_id'] > self::MAX_SAFE_INTEGER))) {
                return false;
            }

            if (is_string($lineItem['id'])) {
                $lineItemIds[$lineItem['id']] = true;
            }
        }

        return true;
    }

    private function hasValidReceiptProposalInput(mixed $input): bool
    {
        if (! is_array($input)
            || ! is_int($input['contract_version'] ?? null)
            || ! is_array($input['transaction'] ?? null)
            || ! is_int($input['transaction']['amount_minor'] ?? null)
            || ! is_array($input['line_items'] ?? null)
            || ! array_is_list($input['line_items'])
            || ! is_string($input['processed_at'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $input['processed_at']) !== 1) {
            return false;
        }

        try {
            $processedAt = CarbonImmutable::parse($input['processed_at']);
        } catch (Throwable) {
            return false;
        }

        if ($processedAt->isFuture()) {
            return false;
        }

        foreach ($input['line_items'] as $lineItem) {
            if (! is_array($lineItem)
                || ! is_int($lineItem['line_total_minor'] ?? null)
                || $lineItem['line_total_minor'] === 0) {
                return false;
            }
        }

        return true;
    }

    private function hasFreshInteraction(mixed $occurredAt): bool
    {
        if (! is_string($occurredAt)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $occurredAt) !== 1) {
            return false;
        }

        try {
            $occurredAtDate = CarbonImmutable::parse($occurredAt);
        } catch (Throwable) {
            return false;
        }

        return $occurredAtDate->lessThanOrEqualTo(now())
            && $occurredAtDate->greaterThanOrEqualTo(
                now()->subSeconds(self::MAXIMUM_INTERACTION_AGE_IN_SECONDS),
            );
    }
}

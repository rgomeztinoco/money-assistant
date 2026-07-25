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
use Illuminate\Validation\Rule;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AuthorizeOpenClawCapability
{
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
        ], true)) {
            return $this->reject($request, 'unsupported_capability');
        }

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

        if ($capability === 'transaction.read') {
            $rules += [
                'input' => ['required', 'array:transaction_id'],
                'input.transaction_id' => ['required', 'integer', 'min:1'],
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

        if ($capability === 'transaction.manual.prepare'
            && ! is_int($payload['input']['amount_minor'] ?? null)) {
            return $this->reject($request, 'invalid_request');
        }

        if ($capability === 'transaction.manual.confirm'
            && ! is_int($payload['input']['pending_operation_revision'] ?? null)) {
            return $this->reject($request, 'invalid_request');
        }

        if (! $this->hasExpectedInteractionBinding($payload['interaction'] ?? null)
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

        return $next($request);
    }

    private function reject(
        Request $request,
        string $outcome,
        int $status = Response::HTTP_UNPROCESSABLE_ENTITY,
    ): JsonResponse {
        $request->attributes->set('openclaw.audit.outcome', $outcome);

        return response()->json(['message' => 'Capability request rejected.'], $status);
    }

    private function hasExpectedInteractionBinding(mixed $interaction): bool
    {
        if (! is_array($interaction) || ($interaction['kind'] ?? null) !== 'owner_message') {
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

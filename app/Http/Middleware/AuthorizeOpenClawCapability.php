<?php

namespace App\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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

        if (($payload['capability'] ?? null) !== 'transaction.read') {
            return $this->reject($request, 'unsupported_capability');
        }

        $topLevelKeys = array_keys($payload);
        sort($topLevelKeys);

        if ($topLevelKeys !== ['capability', 'input', 'interaction', 'schema_version']) {
            return $this->reject($request, 'invalid_request');
        }

        $validator = Validator::make($payload, [
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
            'input' => ['required', 'array:transaction_id'],
            'input.transaction_id' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()
            || ! is_int($payload['input']['transaction_id'] ?? null)
            || ! $this->hasExpectedInteractionBinding($payload['interaction'] ?? null)
            || ! $this->hasFreshInteraction($payload['interaction']['occurred_at'] ?? null)) {
            return $this->reject($request, 'unbound_interaction');
        }

        $request->attributes->set(
            'openclaw.transaction_id',
            $payload['input']['transaction_id'],
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

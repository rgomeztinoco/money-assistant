<?php

namespace App\Http\Middleware;

use App\Models\OpenClawAuditEvent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AuditOpenClawRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $request->attributes->set('openclaw.audit.outcome', 'internal_error');
            $this->record($request, Response::HTTP_INTERNAL_SERVER_ERROR);

            throw $exception;
        }

        if ($request->attributes->get('openclaw.audit.recorded') === true) {
            return $response;
        }

        $this->record($request, $response->getStatusCode());

        return $response;
    }

    private function record(Request $request, int $httpStatus): void
    {
        try {
            $decodedPayload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decodedPayload = null;
        }

        $payload = is_array($decodedPayload) ? $decodedPayload : [];
        $interaction = is_array($payload['interaction'] ?? null)
            ? $payload['interaction']
            : null;
        $schemaVersion = $payload['schema_version'] ?? null;
        $capability = $payload['capability'] ?? null;
        $nonce = $request->attributes->get('openclaw.nonce');

        DB::transaction(fn () => OpenClawAuditEvent::query()->create([
            'occurred_at' => now(),
            'service_key_id' => $request->attributes->get('openclaw.key_id'),
            'schema_version' => is_int($schemaVersion) && $schemaVersion > 0 && $schemaVersion <= 65535
                ? $schemaVersion
                : null,
            'capability' => is_string($capability) && mb_strlen($capability) <= 64
                ? $capability
                : null,
            'outcome' => $request->attributes->get(
                'openclaw.audit.outcome',
                $this->defaultOutcome($httpStatus),
            ),
            'http_status' => $httpStatus,
            'nonce_digest' => hash('sha256', is_string($nonce) ? $nonce : ''),
            'request_digest' => hash('sha256', $request->getContent()),
            'interaction_digest' => $interaction === null
                ? null
                : hash('sha256', json_encode([
                    'kind' => $interaction['kind'] ?? null,
                    'agent_id' => $interaction['agent_id'] ?? null,
                    'account_id' => $interaction['account_id'] ?? null,
                    'conversation_id' => $interaction['conversation_id'] ?? null,
                    'owner_sender_id' => $interaction['owner_sender_id'] ?? null,
                    'message_id' => $interaction['message_id'] ?? null,
                ], JSON_THROW_ON_ERROR)),
            'resource_type' => is_string($capability) && Str::startsWith($capability, 'transaction.')
                ? 'transaction'
                : null,
            'result_count' => $request->attributes->get('openclaw.audit.result_count', 0),
        ]));
    }

    private function defaultOutcome(int $httpStatus): string
    {
        return match ($httpStatus) {
            Response::HTTP_NOT_FOUND => 'not_found',
            Response::HTTP_TOO_MANY_REQUESTS => 'rate_limited',
            default => $httpStatus < 400 ? 'success' : 'invalid_request',
        };
    }
}

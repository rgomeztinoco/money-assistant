<?php

namespace App\Actions\OpenClaw;

use App\Exceptions\IdempotencyKeyConflict;
use App\Models\OpenClawPendingOperation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

class PrepareFinancialExport
{
    private const string CAPABILITY = 'financial.export.prepare';

    public function __construct(
        private BuildFinancialExport $buildFinancialExport,
        private ComputeOpenClawPayloadDigest $computePayloadDigest,
    ) {}

    /** @throws JsonException */
    public function handle(
        User $owner,
        string $serviceKeyId,
        int $schemaVersion,
        string $conversationId,
        string $preparationInteractionDigest,
        CarbonImmutable $preparationOccurredAt,
        string $idempotencyKey,
    ): OpenClawPendingOperation {
        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException('The idempotency key must be a valid UUID.');
        }

        return DB::transaction(function () use (
            $owner,
            $serviceKeyId,
            $schemaVersion,
            $conversationId,
            $preparationInteractionDigest,
            $preparationOccurredAt,
            $idempotencyKey,
        ): OpenClawPendingOperation {
            $owner = User::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

            $export = $this->buildFinancialExport->handle($owner);
            $transactionCount = $export->transactionCount;
            $payload = [
                'owner_state_digest' => $export->digest,
                'transaction_count' => $transactionCount,
            ];
            $payloadDigest = $this->computePayloadDigest->handle($payload);
            $conversationDigest = hash('sha256', $conversationId);
            $existingOperation = OpenClawPendingOperation::query()
                ->where('service_key_id', $serviceKeyId)
                ->where('schema_version', $schemaVersion)
                ->where('capability', self::CAPABILITY)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingOperation !== null) {
                if (! hash_equals($existingOperation->payload_digest, $payloadDigest)
                    || ! hash_equals(
                        $existingOperation->preparation_interaction_digest,
                        $preparationInteractionDigest,
                    )
                    || ! $existingOperation->preparation_occurred_at->equalTo($preparationOccurredAt)) {
                    throw new IdempotencyKeyConflict;
                }

                return $existingOperation;
            }

            OpenClawPendingOperation::query()
                ->whereBelongsTo($owner, 'owner')
                ->where('conversation_digest', $conversationDigest)
                ->whereNull('canceled_at')
                ->whereNull('confirmed_at')
                ->update([
                    'canceled_at' => now(),
                    'revision' => DB::raw('revision + 1'),
                    'updated_at' => now(),
                ]);

            $transactionLabel = $transactionCount === 1 ? 'Transaction' : 'Transactions';

            return OpenClawPendingOperation::query()->create([
                'user_id' => $owner->getKey(),
                'operation_id' => (string) Str::uuid(),
                'service_key_id' => $serviceKeyId,
                'schema_version' => $schemaVersion,
                'capability' => self::CAPABILITY,
                'conversation_digest' => $conversationDigest,
                'idempotency_key' => $idempotencyKey,
                'payload_digest' => $payloadDigest,
                'payload' => $payload,
                'effect_summary' => "Prepare a complete financial data export containing {$transactionCount} {$transactionLabel}.",
                'preparation_interaction_digest' => $preparationInteractionDigest,
                'preparation_occurred_at' => $preparationOccurredAt,
                'expires_at' => now()->addMinutes(30),
            ]);
        }, 3);
    }
}

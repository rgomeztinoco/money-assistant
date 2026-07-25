<?php

namespace App\Actions\Ledger;

use App\Currency;
use App\Exceptions\IdempotencyKeyConflict;
use App\Models\OpenClawPendingOperation;
use App\Models\User;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

final class PrepareOpenClawManualTransaction
{
    private const string CAPABILITY = 'transaction.manual.prepare';

    /**
     * @throws JsonException
     */
    public function handle(
        User $owner,
        string $serviceKeyId,
        int $schemaVersion,
        string $conversationId,
        string $preparationInteractionDigest,
        CarbonImmutable $preparationOccurredAt,
        string $idempotencyKey,
        CarbonImmutable $occurredOn,
        int $amountMinor,
        Currency $currency,
        TransactionKind $kind,
        string $merchantDescription,
    ): OpenClawPendingOperation {
        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException('The idempotency key must be a valid UUID.');
        }

        $merchantDescription = Str::squish($merchantDescription);
        $payload = [
            'occurred_on' => $occurredOn->toDateString(),
            'amount_minor' => $amountMinor,
            'currency' => $currency->value,
            'kind' => $kind->value,
            'merchant_description' => $merchantDescription,
        ];
        $payloadDigest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $conversationDigest = hash('sha256', $conversationId);

        return DB::transaction(function () use (
            $owner,
            $serviceKeyId,
            $schemaVersion,
            $conversationDigest,
            $preparationInteractionDigest,
            $preparationOccurredAt,
            $idempotencyKey,
            $payload,
            $payloadDigest,
            $occurredOn,
            $amountMinor,
            $currency,
            $kind,
            $merchantDescription,
        ): OpenClawPendingOperation {
            User::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

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
                'effect_summary' => $this->effectSummary(
                    $occurredOn,
                    $amountMinor,
                    $currency,
                    $kind,
                    $merchantDescription,
                ),
                'preparation_interaction_digest' => $preparationInteractionDigest,
                'preparation_occurred_at' => $preparationOccurredAt,
                'expires_at' => now()->addMinutes(30),
            ]);
        }, 3);
    }

    private function effectSummary(
        CarbonImmutable $occurredOn,
        int $amountMinor,
        Currency $currency,
        TransactionKind $kind,
        string $merchantDescription,
    ): string {
        $amount = sprintf('%d.%02d', intdiv($amountMinor, 100), $amountMinor % 100);
        $operation = $kind === TransactionKind::Refund ? 'Refund' : 'purchase';

        return "Record a {$operation} of {$currency->value} {$amount} on {$occurredOn->toDateString()} at {$merchantDescription}.";
    }
}

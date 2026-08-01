<?php

namespace App\Actions\OpenClaw;

use App\Actions\Categorization\EnsureCategoryCanBeDeleted;
use App\Actions\ReceiptReconciliation\EnsureReceiptBreakdownCanBeDiscarded;
use App\Exceptions\IdempotencyKeyConflict;
use App\Exceptions\StaleCategoryRevision;
use App\Exceptions\StaleReceiptBreakdownRevision;
use App\Models\Category;
use App\Models\OpenClawPendingOperation;
use App\Models\ReceiptBreakdown;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

final class PrepareFinancialDeletion
{
    private const string CAPABILITY = 'financial.deletion.prepare';

    public function __construct(
        private EnsureCategoryCanBeDeleted $ensureCategoryCanBeDeleted,
        private EnsureReceiptBreakdownCanBeDiscarded $ensureReceiptBreakdownCanBeDiscarded,
        private ComputeOpenClawPayloadDigest $computePayloadDigest,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws JsonException
     */
    public function handle(
        User $owner,
        string $serviceKeyId,
        int $schemaVersion,
        string $conversationId,
        string $preparationInteractionDigest,
        CarbonImmutable $preparationOccurredAt,
        array $input,
    ): OpenClawPendingOperation {
        $input = $this->normalizedInput($input);

        return DB::transaction(function () use (
            $owner,
            $serviceKeyId,
            $schemaVersion,
            $conversationId,
            $preparationInteractionDigest,
            $preparationOccurredAt,
            $input,
        ): OpenClawPendingOperation {
            $owner = User::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();
            [$payload, $effectSummary] = $this->validatedTarget($owner, $input);
            $payloadDigest = $this->computePayloadDigest->handle($payload);
            $conversationDigest = hash('sha256', $conversationId);
            $existingOperation = OpenClawPendingOperation::query()
                ->where('service_key_id', $serviceKeyId)
                ->where('schema_version', $schemaVersion)
                ->where('capability', self::CAPABILITY)
                ->where('idempotency_key', $input['idempotency_key'])
                ->first();

            if ($existingOperation !== null) {
                if (! hash_equals($existingOperation->payload_digest, $payloadDigest)
                    || ! hash_equals($existingOperation->preparation_interaction_digest, $preparationInteractionDigest)
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
                'user_id' => $owner->id,
                'operation_id' => (string) Str::uuid(),
                'service_key_id' => $serviceKeyId,
                'schema_version' => $schemaVersion,
                'capability' => self::CAPABILITY,
                'conversation_digest' => $conversationDigest,
                'idempotency_key' => $input['idempotency_key'],
                'payload_digest' => $payloadDigest,
                'payload' => $payload,
                'effect_summary' => $effectSummary,
                'preparation_interaction_digest' => $preparationInteractionDigest,
                'preparation_occurred_at' => $preparationOccurredAt,
                'expires_at' => now()->addMinutes(30),
            ]);
        }, 3);
    }

    /**
     * @param  array{idempotency_key: string, resource_type: 'category'|'receipt_breakdown', resource_id: int, expected_revision: int}  $input
     * @return array{array{resource_type: 'category'|'receipt_breakdown', resource_id: int, expected_revision: int}, string}
     */
    private function validatedTarget(User $owner, array $input): array
    {
        if ($input['resource_type'] === 'category') {
            $category = Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($input['resource_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($category->revision !== $input['expected_revision']) {
                throw new StaleCategoryRevision;
            }

            $this->ensureCategoryCanBeDeleted->handle($category);

            return [[
                'resource_type' => 'category',
                'resource_id' => $category->id,
                'expected_revision' => $category->revision,
            ], "Delete Category {$category->name} into 30-day recoverable trash before payload-free purge."];
        }

        $breakdown = ReceiptBreakdown::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereKey($input['resource_id'])
            ->where('status', 'draft')
            ->lockForUpdate()
            ->firstOrFail();

        if ($breakdown->revision !== $input['expected_revision']) {
            throw StaleReceiptBreakdownRevision::fromBreakdown($breakdown);
        }

        $this->ensureReceiptBreakdownCanBeDiscarded->handle($breakdown);

        return [[
            'resource_type' => 'receipt_breakdown',
            'resource_id' => $breakdown->id,
            'expected_revision' => $breakdown->revision,
        ], "Delete Receipt Breakdown draft {$breakdown->id} into 30-day recoverable trash before payload-free purge."];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{idempotency_key: string, resource_type: 'category'|'receipt_breakdown', resource_id: int, expected_revision: int}
     */
    private function normalizedInput(array $input): array
    {
        $idempotencyKey = $input['idempotency_key'] ?? null;
        $resourceType = $input['resource_type'] ?? null;
        $resourceId = $input['resource_id'] ?? null;
        $expectedRevision = $input['expected_revision'] ?? null;

        if (! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException('The idempotency key must be a valid UUID.');
        }

        if (! is_string($resourceType)
            || ! in_array($resourceType, ['category', 'receipt_breakdown'], true)
            || ! is_int($resourceId)
            || $resourceId < 1
            || ! is_int($expectedRevision)
            || $expectedRevision < 1) {
            throw new InvalidArgumentException('The deletion target is invalid.');
        }

        return [
            'idempotency_key' => $idempotencyKey,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'expected_revision' => $expectedRevision,
        ];
    }
}

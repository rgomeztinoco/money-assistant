<?php

namespace App\Actions\ReceiptReconciliation;

use App\ExactInteger;
use App\Exceptions\IdempotencyKeyConflict;
use App\Exceptions\StaleReceiptBreakdownRevision;
use App\Exceptions\StaleTransactionRevision;
use App\LineItemRole;
use App\Models\Category;
use App\Models\OpenClawPendingOperation;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PrepareOpenClawReceiptBreakdown
{
    private const string CAPABILITY = 'receipt.breakdown.mutation.prepare';

    public function __construct(
        private ResolveReceiptAdjustmentCategories $resolveAdjustmentCategories,
    ) {}

    /** @param array<string, mixed> $input */
    public function handle(
        User $owner,
        string $serviceKeyId,
        int $schemaVersion,
        string $conversationId,
        string $preparationInteractionDigest,
        CarbonImmutable $preparationOccurredAt,
        array $input,
    ): OpenClawPendingOperation {
        $idempotencyKey = $input['idempotency_key'] ?? null;

        if (! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException('The idempotency key must be a valid UUID.');
        }

        unset($input['idempotency_key']);

        return DB::transaction(function () use (
            $owner,
            $serviceKeyId,
            $schemaVersion,
            $conversationId,
            $preparationInteractionDigest,
            $preparationOccurredAt,
            $idempotencyKey,
            $input,
        ): OpenClawPendingOperation {
            User::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();
            $payload = $this->validatedPayload($owner, $input);
            $payloadDigest = hash('sha256', $this->canonicalJson($payload));
            $conversationDigest = hash('sha256', $conversationId);
            $existingOperation = OpenClawPendingOperation::query()
                ->where('service_key_id', $serviceKeyId)
                ->where('schema_version', $schemaVersion)
                ->where('capability', self::CAPABILITY)
                ->where('idempotency_key', $idempotencyKey)
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
                'user_id' => $owner->getKey(),
                'operation_id' => (string) Str::uuid(),
                'service_key_id' => $serviceKeyId,
                'schema_version' => $schemaVersion,
                'capability' => self::CAPABILITY,
                'conversation_digest' => $conversationDigest,
                'idempotency_key' => $idempotencyKey,
                'payload_digest' => $payloadDigest,
                'payload' => $payload,
                'effect_summary' => $this->effectSummary($payload),
                'preparation_interaction_digest' => $preparationInteractionDigest,
                'preparation_occurred_at' => $preparationOccurredAt,
                'expires_at' => now()->addMinutes(30),
            ]);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validatedPayload(User $owner, array $input): array
    {
        $operation = $input['operation'] ?? null;

        if (! in_array($operation, ['create_draft', 'update_draft', 'confirm_draft'], true)) {
            throw new InvalidArgumentException('Receipt Breakdown input is invalid.');
        }

        if ($operation === 'create_draft') {
            $transactionId = $input['transaction_id'] ?? null;
            $expectedTransactionRevision = $input['expected_transaction_revision'] ?? null;

            if (! is_int($transactionId) || ! is_int($expectedTransactionRevision)) {
                throw new InvalidArgumentException('Receipt Breakdown input is invalid.');
            }

            $transaction = Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($transactionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transaction->revision !== $expectedTransactionRevision) {
                throw StaleTransactionRevision::fromTransaction($transaction);
            }

            if ($transaction->voided_at !== null) {
                throw ValidationException::withMessages([
                    'transaction_id' => 'A Receipt Breakdown cannot attach to a Voided Transaction.',
                ]);
            }

            if ($transaction->receiptBreakdowns()->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages([
                    'transaction_id' => 'This Transaction already has a draft Receipt Breakdown.',
                ]);
            }

            $payload = [
                'operation' => $operation,
                'transaction_id' => $transaction->id,
                'transaction_revision' => $transaction->revision,
            ];
            $currentLineItems = collect();
        } else {
            $breakdownId = $input['receipt_breakdown_id'] ?? null;
            $expectedRevision = $input['expected_revision'] ?? null;

            if (! is_int($breakdownId) || ! is_int($expectedRevision)) {
                throw new InvalidArgumentException('Receipt Breakdown input is invalid.');
            }

            $draft = ReceiptBreakdown::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($breakdownId)
                ->where('status', 'draft')
                ->with('transaction:id,revision,amount_minor')
                ->lockForUpdate()
                ->firstOrFail();

            if ($draft->revision !== $expectedRevision) {
                throw StaleReceiptBreakdownRevision::fromBreakdown($draft);
            }

            $payload = [
                'operation' => $operation,
                'receipt_breakdown_id' => $draft->id,
                'expected_revision' => $draft->revision,
                'transaction_id' => $draft->transaction_id,
                'transaction_revision' => $draft->transaction->revision,
            ];

            if ($operation === 'confirm_draft') {
                $categoryIds = $draft->lineItems()
                    ->whereNotNull('category_id')
                    ->pluck('category_id')
                    ->unique()
                    ->values();
                $categories = Category::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->whereIn('id', $categoryIds)
                    ->whereNull('retired_at')
                    ->lockForUpdate()
                    ->get(['id', 'revision']);

                if ($categories->count() !== $categoryIds->count()) {
                    throw ValidationException::withMessages([
                        'line_items' => 'Every assigned Line Item Category must be active and owned by you.',
                    ]);
                }

                $payload['category_revisions'] = $categories
                    ->sortBy('id')
                    ->map(fn (Category $category): array => [
                        'id' => $category->id,
                        'revision' => $category->revision,
                    ])
                    ->values()
                    ->all();

                return $payload;
            }

            $currentLineItems = $draft->lineItems()->lockForUpdate()->get()->keyBy('line_item_id');
        }

        $lineItems = $input['line_items'] ?? null;

        if (! is_array($lineItems) || ! array_is_list($lineItems)) {
            throw new InvalidArgumentException('Receipt Breakdown Line Items are invalid.');
        }

        $normalizedLineItems = [];

        foreach ($lineItems as $lineItem) {
            if (! is_array($lineItem)
                || (! is_string($lineItem['id'] ?? null) && ($lineItem['id'] ?? null) !== null)
                || ! is_string($lineItem['description'] ?? null)
                || (! is_string($lineItem['role'] ?? null) && array_key_exists('role', $lineItem))
                || (! is_string($lineItem['quantity'] ?? null) && ($lineItem['quantity'] ?? null) !== null)
                || (! is_string($lineItem['related_line_item_id'] ?? null)
                    && ($lineItem['related_line_item_id'] ?? null) !== null)
                || (! is_int($lineItem['unit_price_minor'] ?? null)
                    && ($lineItem['unit_price_minor'] ?? null) !== null)
                || ! is_int($lineItem['line_total_minor'] ?? null)
                || (! is_int($lineItem['category_id'] ?? null) && ($lineItem['category_id'] ?? null) !== null)) {
                throw new InvalidArgumentException('Receipt Breakdown Line Item input is invalid.');
            }

            $role = LineItemRole::tryFrom($lineItem['role'] ?? LineItemRole::PurchasedItem->value);
            $quantity = $lineItem['quantity'] ?? null;

            if ($role === null
                || ! $role->acceptsLineTotal($lineItem['line_total_minor'])
                || ($quantity !== null
                    && preg_match('/^(?=.*[1-9])\d+(?:\.\d{1,6})?$/D', $quantity) !== 1)
                || ($role === LineItemRole::Unidentified && $lineItem['category_id'] !== null)) {
                throw new InvalidArgumentException('Receipt Breakdown Line Item input is invalid.');
            }

            $normalizedLineItems[] = [
                'id' => $lineItem['id'] ?? null,
                'description' => $lineItem['description'],
                'role' => $role->value,
                'quantity' => $quantity,
                'unit_price_minor' => $lineItem['unit_price_minor'] ?? null,
                'line_total_minor' => $lineItem['line_total_minor'],
                'category_id' => $lineItem['category_id'],
                'related_line_item_id' => $lineItem['related_line_item_id'] ?? null,
            ];
        }

        $submittedIds = collect($normalizedLineItems)->pluck('id')->filter();

        if ($submittedIds->duplicates()->isNotEmpty()
            || $submittedIds->diff($currentLineItems->keys())->isNotEmpty()
            || ($operation === 'create_draft'
                && collect($normalizedLineItems)->contains(
                    fn (array $lineItem): bool => $lineItem['related_line_item_id'] !== null,
                ))) {
            throw ValidationException::withMessages([
                'line_items' => 'Every retained Line Item identity must belong to this draft and appear once.',
            ]);
        }

        $normalizedLineItems = $this->resolveAdjustmentCategories->handle($normalizedLineItems);

        $categoryIds = collect($normalizedLineItems)->pluck('category_id')->filter()->unique()->values();
        $categories = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereIn('id', $categoryIds)
            ->whereNull('retired_at')
            ->lockForUpdate()
            ->get(['id', 'revision']);

        if ($categories->count() !== $categoryIds->count()) {
            throw ValidationException::withMessages([
                'line_items' => 'Every assigned Line Item Category must be active and owned by you.',
            ]);
        }

        $payload['line_items'] = $normalizedLineItems;
        $payload['category_revisions'] = $categories
            ->sortBy('id')
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'revision' => $category->revision,
            ])
            ->values()
            ->all();

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function effectSummary(array $payload): string
    {
        if ($payload['operation'] === 'confirm_draft') {
            return sprintf(
                'Confirm draft Receipt Breakdown #%d at revision %d only if its signed Line Item total exactly reconciles.',
                $payload['receipt_breakdown_id'],
                $payload['expected_revision'],
            );
        }

        $total = ExactInteger::from(0);

        foreach ($payload['line_items'] as $lineItem) {
            $total = $total->add(ExactInteger::from($lineItem['line_total_minor']));
        }

        if ($payload['operation'] === 'create_draft') {
            return sprintf(
                'Create a draft Receipt Breakdown for Transaction #%d at revision %d with %d Line Items totaling %s minor units.',
                $payload['transaction_id'],
                $payload['transaction_revision'],
                count($payload['line_items']),
                $total->value(),
            );
        }

        return sprintf(
            'Replace draft Receipt Breakdown #%d at revision %d with %d Line Items totaling %s minor units.',
            $payload['receipt_breakdown_id'],
            $payload['expected_revision'],
            count($payload['line_items']),
            $total->value(),
        );
    }

    /** @param array<mixed> $value */
    private function canonicalJson(array $value): string
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = json_decode($this->canonicalJson($item), true, flags: JSON_THROW_ON_ERROR);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}

<?php

namespace App\Actions\Categorization;

use App\Exceptions\CategoryOperationBlocked;
use App\Exceptions\IdempotencyKeyConflict;
use App\Exceptions\StaleCategoryRevision;
use App\Exceptions\StaleTransactionRevision;
use App\Models\Category;
use App\Models\OpenClawPendingOperation;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;

final class PrepareOpenClawCategorization
{
    private const string CAPABILITY = 'category.mutation.prepare';

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
            $payloadDigest = $this->payloadDigest($payload);
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
        $operation = $input['operation'];

        if ($operation === 'create') {
            $payload = $this->normalizedCategoryPayload($input);
            $this->validateParentAndName($owner, $payload, null);

            return $payload;
        }

        if (in_array($operation, ['update', 'retire', 'reactivate'], true)) {
            $category = Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($input['category_id'])
                ->firstOrFail();

            if ($category->revision !== $input['expected_revision']) {
                throw new StaleCategoryRevision;
            }

            if ($operation === 'update') {
                $payload = $this->normalizedCategoryPayload($input);
                $payload['category_id'] = $category->id;
                $payload['expected_revision'] = $category->revision;

                if ($payload['parent_id'] !== null && $category->children()->exists()) {
                    throw new CategoryOperationBlocked('Move or retire this Category’s children before making it a child.');
                }

                $this->validateParentAndName($owner, $payload, $category);

                return $payload;
            }

            if ($operation === 'retire' && $category->children()->whereNull('retired_at')->exists()) {
                throw new CategoryOperationBlocked('Move or retire every active child Category first.');
            }

            $parent = $operation === 'reactivate'
                ? $this->validateReactivation($owner, $category)
                : null;

            return [
                'operation' => $operation,
                'category_id' => $category->id,
                'expected_revision' => $category->revision,
                'category_name' => $category->name,
                'parent_id' => $parent?->id,
                'parent_revision' => $parent?->revision,
            ];
        }

        $transaction = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereKey($input['transaction_id'])
            ->firstOrFail();

        if ($transaction->revision !== $input['expected_revision']) {
            throw StaleTransactionRevision::fromTransaction($transaction);
        }

        $category = $input['category_id'] === null
            ? null
            : Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($input['category_id'])
                ->whereNull('retired_at')
                ->first();

        if ($input['category_id'] !== null && $category === null) {
            throw ValidationException::withMessages([
                'category_id' => 'Choose an active Category owned by you.',
            ]);
        }

        return [
            'operation' => 'assign_transaction',
            'transaction_id' => $transaction->id,
            'expected_revision' => $transaction->revision,
            'category_id' => $category?->id,
            'category_name' => $category?->name,
            'category_revision' => $category?->revision,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{operation: string, name: string, parent_id: int|null, parent_name: string|null, parent_revision: int|null, description: string|null, examples: list<string>}
     */
    private function normalizedCategoryPayload(array $input): array
    {
        $operation = $input['operation'] ?? null;
        $name = $input['name'] ?? null;
        $parentId = $input['parent_id'] ?? null;
        $descriptionInput = $input['description'] ?? null;
        $examplesInput = $input['examples'] ?? null;

        if (! is_string($operation)
            || ! is_string($name)
            || ($parentId !== null && ! is_int($parentId))
            || ($descriptionInput !== null && ! is_string($descriptionInput))
            || ! is_array($examplesInput)) {
            throw new InvalidArgumentException('Category input is invalid.');
        }

        $description = Str::squish((string) $descriptionInput);
        $examples = [];
        $seenExamples = [];

        foreach ($examplesInput as $example) {
            if (! is_string($example)) {
                throw new InvalidArgumentException('Category examples must be strings.');
            }

            $example = Str::squish($example);
            $comparisonKey = Str::lower($example);

            if ($example !== '' && ! isset($seenExamples[$comparisonKey])) {
                $examples[] = $example;
                $seenExamples[$comparisonKey] = true;
            }
        }

        return [
            'operation' => $operation,
            'name' => Str::squish($name),
            'parent_id' => $parentId,
            'parent_name' => null,
            'parent_revision' => null,
            'description' => $description === '' ? null : $description,
            'examples' => $examples,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function validateParentAndName(User $owner, array &$payload, ?Category $category): void
    {
        $parent = $payload['parent_id'] === null
            ? null
            : Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($payload['parent_id'])
                ->whereNull('parent_id')
                ->whereNull('retired_at')
                ->first();

        if ($payload['parent_id'] !== null && ($parent === null || $parent->id === $category?->id)) {
            throw ValidationException::withMessages([
                'parent_id' => 'Choose an active top-level Category owned by you.',
            ]);
        }

        $payload['parent_name'] = $parent?->name;
        $payload['parent_revision'] = $parent?->revision;

        if ($category?->retired_at !== null) {
            return;
        }

        $nameConflict = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->when($category !== null, fn ($query) => $query->whereKeyNot($category->id))
            ->whereNull('retired_at')
            ->whereRaw('lower(name) = lower(?)', [$payload['name']])
            ->when(
                $parent === null,
                fn ($query) => $query->whereNull('parent_id'),
                fn ($query) => $query->where('parent_id', $parent->id),
            )
            ->exists();

        if ($nameConflict) {
            throw ValidationException::withMessages([
                'name' => 'An active sibling Category already uses this name.',
            ]);
        }
    }

    private function validateReactivation(User $owner, Category $category): ?Category
    {
        $parent = $category->parent_id === null
            ? null
            : Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($category->parent_id)
                ->whereNull('retired_at')
                ->first();

        if ($category->parent_id !== null && $parent === null) {
            throw new CategoryOperationBlocked('Reactivate or move the parent Category first.');
        }

        $nameConflict = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereKeyNot($category->id)
            ->whereNull('retired_at')
            ->whereRaw('lower(name) = lower(?)', [$category->name])
            ->when(
                $category->parent_id === null,
                fn ($query) => $query->whereNull('parent_id'),
                fn ($query) => $query->where('parent_id', $category->parent_id),
            )
            ->exists();

        if ($nameConflict) {
            throw new CategoryOperationBlocked('Rename this Category or the active sibling with the same name first.');
        }

        return $parent;
    }

    /** @param array<string, mixed> $payload */
    private function effectSummary(array $payload): string
    {
        return match ($payload['operation']) {
            'create' => sprintf(
                'Create the %s Category "%s". %s %s',
                $payload['parent_name'] === null
                    ? 'top-level'
                    : 'second-level under "'.$payload['parent_name'].'"',
                $payload['name'],
                $this->descriptionSummary($payload['description']),
                $this->examplesSummary($payload['examples']),
            ),
            'update' => sprintf(
                'Update Category #%d at revision %d to "%s"%s. %s %s',
                $payload['category_id'],
                $payload['expected_revision'],
                $payload['name'],
                $payload['parent_name'] === null
                    ? ' as a top-level Category'
                    : ' under "'.$payload['parent_name'].'"',
                $this->descriptionSummary($payload['description']),
                $this->examplesSummary($payload['examples']),
            ),
            'retire' => sprintf(
                'Retire the Category "%s" (#%d) at revision %d without changing historical assignments.',
                $payload['category_name'],
                $payload['category_id'],
                $payload['expected_revision'],
            ),
            'reactivate' => sprintf(
                'Reactivate the Category "%s" (#%d) at revision %d without reactivating automation.',
                $payload['category_name'],
                $payload['category_id'],
                $payload['expected_revision'],
            ),
            default => $payload['category_id'] === null
                ? sprintf(
                    'Return Transaction #%d to Uncategorized at revision %d.',
                    $payload['transaction_id'],
                    $payload['expected_revision'],
                )
                : sprintf(
                    'Assign the Category "%s" to Transaction #%d at revision %d.',
                    $payload['category_name'],
                    $payload['transaction_id'],
                    $payload['expected_revision'],
                ),
        };
    }

    private function descriptionSummary(?string $description): string
    {
        return $description === null
            ? 'No guidance description.'
            : 'Guidance description: '.json_encode(
                $description,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ).'.';
    }

    /** @param list<string> $examples */
    private function examplesSummary(array $examples): string
    {
        return $examples === []
            ? 'No guidance examples.'
            : 'Guidance examples: '.json_encode(
                $examples,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ).'.';
    }

    /** @param array<string, mixed> $payload */
    private function payloadDigest(array $payload): string
    {
        ksort($payload);

        foreach ($payload as &$value) {
            if (is_array($value) && ! array_is_list($value)) {
                ksort($value);
            }
        }

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}

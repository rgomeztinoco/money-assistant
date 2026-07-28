<?php

namespace App\Actions\Categorization;

use App\AiClassificationError;
use App\AiClassificationInput;
use App\AiClassificationOutcome;
use App\CategoryAssignmentProvenance;
use App\Contracts\AiClassifier;
use App\Exceptions\AiClassifierTimedOut;
use App\Exceptions\AiClassifierUnavailable;
use App\MerchantNormalizer;
use App\Models\AiClassificationRequest;
use App\Models\Category;
use App\Models\CategoryAssignment;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ClassifyTransactionWithAi
{
    public function __construct(
        private AiClassifier $aiClassifier,
        private MerchantNormalizer $merchantNormalizer,
    ) {}

    public function handle(int $classificationRequestId): void
    {
        $classificationRequest = DB::transaction(function () use ($classificationRequestId): ?AiClassificationRequest {
            $classificationRequest = AiClassificationRequest::query()
                ->with('transaction')
                ->lockForUpdate()
                ->find($classificationRequestId);

            if ($classificationRequest === null
                || $classificationRequest->completed_at !== null
                || ($classificationRequest->next_attempt_at !== null && $classificationRequest->next_attempt_at->isFuture())
                || ($classificationRequest->claimed_at !== null && $classificationRequest->claimed_at->isAfter(now()->subMinute()))) {
                return null;
            }

            $transaction = $classificationRequest->transaction;

            if ($this->hasAuthoritativeAssignment($transaction)) {
                $this->completeAsSuperseded($classificationRequest);

                return null;
            }

            if ($this->hasStaleInput($classificationRequest, $transaction)) {
                $this->refreshForRevision($classificationRequest, $transaction);

                return null;
            }

            $classificationRequest->forceFill([
                'attempt_count' => $classificationRequest->attempt_count + 1,
                'queued_at' => null,
                'claimed_at' => now(),
                'last_attempted_at' => now(),
            ])->save();

            return $classificationRequest;
        }, 3);

        if ($classificationRequest === null) {
            return;
        }

        $categories = $this->activeCategories($classificationRequest->user_id);
        try {
            $result = $this->aiClassifier->classify(new AiClassificationInput(
                merchantDescription: $this->merchantNormalizer->normalize(
                    $classificationRequest->transaction->merchant_description,
                ),
                kind: $classificationRequest->transaction->kind->value,
                amountMinor: $classificationRequest->transaction->amount_minor,
                currency: $classificationRequest->transaction->currency->value,
                categories: $this->categoryGuidance($categories),
            ));
        } catch (AiClassifierTimedOut) {
            $this->recordClassifierFailure(
                $classificationRequest,
                AiClassificationOutcome::Timeout,
            );

            return;
        } catch (AiClassifierUnavailable) {
            $this->recordClassifierFailure(
                $classificationRequest,
                AiClassificationOutcome::Unavailable,
            );

            return;
        }

        $offeredCategoryId = null;

        if ($result->categoryPath !== null) {
            $matchingCategories = $categories->filter(
                fn (Category $category): bool => $this->categoryPath($category) === $result->categoryPath,
            );

            if ($matchingCategories->count() === 1) {
                $offeredCategoryId = $matchingCategories->first()?->id;
            }
        }

        DB::transaction(function () use ($classificationRequest, $offeredCategoryId, $result): void {
            $currentRequest = AiClassificationRequest::query()
                ->lockForUpdate()
                ->find($classificationRequest->id);
            $transaction = Transaction::query()
                ->lockForUpdate()
                ->find($classificationRequest->transaction_id);

            if ($currentRequest === null
                || $transaction === null
                || $currentRequest->completed_at !== null
                || ! $currentRequest->claimed_at?->equalTo($classificationRequest->claimed_at)) {
                return;
            }

            if ($this->hasAuthoritativeAssignment($transaction)) {
                $this->completeAsSuperseded($currentRequest);

                return;
            }

            if ($this->hasStaleInput($currentRequest, $transaction)) {
                $this->refreshForRevision($currentRequest, $transaction);

                return;
            }

            $category = $offeredCategoryId === null
                ? null
                : Category::query()
                    ->where('user_id', $transaction->user_id)
                    ->whereKey($offeredCategoryId)
                    ->whereNull('retired_at')
                    ->where(fn ($query) => $query
                        ->whereNull('parent_id')
                        ->orWhereHas('parent', fn ($query) => $query->whereNull('retired_at')))
                    ->lockForUpdate()
                    ->first();
            $outcome = match (true) {
                $category === null => AiClassificationOutcome::InvalidCategory,
                $result->confidence >= 60 => AiClassificationOutcome::Medium,
                default => AiClassificationOutcome::LowConfidence,
            };
            $categoryId = $outcome === AiClassificationOutcome::Medium
                ? $category->id
                : null;
            $previousCategoryId = $transaction->category_id;
            $transaction->category_id = $categoryId;
            $transaction->category_assignment_provenance = $categoryId === null
                ? null
                : CategoryAssignmentProvenance::Ai;
            $transaction->revision++;
            $transaction->save();

            CategoryAssignment::create([
                'user_id' => $transaction->user_id,
                'transaction_id' => $transaction->id,
                'category_id' => $categoryId,
                'previous_category_id' => $previousCategoryId,
                'source' => CategoryAssignmentProvenance::Ai,
                'transaction_revision' => $transaction->revision,
                'ai_classifier_version' => $this->aiClassifier->version(),
                'ai_confidence' => $result->confidence,
                'ai_outcome' => $outcome,
                'ai_explanation' => $result->explanation,
            ]);

            $currentRequest->forceFill([
                'completed_at' => now(),
                'terminal_outcome' => $outcome,
                'claimed_at' => null,
                'next_attempt_at' => null,
                'last_error_code' => null,
            ])->save();
        }, 3);
    }

    private function hasStaleInput(
        AiClassificationRequest $classificationRequest,
        Transaction $transaction,
    ): bool {
        return $transaction->revision !== $classificationRequest->expected_transaction_revision;
    }

    private function hasAuthoritativeAssignment(Transaction $transaction): bool
    {
        return ! CategoryAssignmentProvenance::Ai->canReplace(
            $transaction->category_assignment_provenance,
        );
    }

    private function refreshForRevision(
        AiClassificationRequest $classificationRequest,
        Transaction $transaction,
    ): void {
        $classificationRequest->forceFill([
            'expected_transaction_revision' => $transaction->revision,
            'attempt_count' => 0,
            'next_attempt_at' => null,
            'queued_at' => null,
            'claimed_at' => null,
            'last_attempted_at' => null,
            'completed_at' => null,
            'terminal_outcome' => null,
            'last_error_code' => null,
        ])->save();
    }

    private function completeAsSuperseded(
        AiClassificationRequest $classificationRequest,
    ): void {
        $classificationRequest->forceFill([
            'completed_at' => now(),
            'terminal_outcome' => AiClassificationOutcome::Superseded,
            'queued_at' => null,
            'claimed_at' => null,
            'next_attempt_at' => null,
            'last_error_code' => AiClassificationError::AuthoritativeAssignment,
        ])->save();
    }

    private function classifierError(
        AiClassificationOutcome $outcome,
    ): AiClassificationError {
        return match ($outcome) {
            AiClassificationOutcome::Timeout => AiClassificationError::ClassifierTimeout,
            AiClassificationOutcome::Unavailable => AiClassificationError::ClassifierUnavailable,
            default => throw new \LogicException('Only classifier failures have error codes.'),
        };
    }

    private function recordClassifierFailure(
        AiClassificationRequest $classificationRequest,
        AiClassificationOutcome $outcome,
    ): void {
        DB::transaction(function () use ($classificationRequest, $outcome): void {
            $currentRequest = AiClassificationRequest::query()
                ->lockForUpdate()
                ->find($classificationRequest->id);
            $transaction = Transaction::query()
                ->lockForUpdate()
                ->find($classificationRequest->transaction_id);

            if ($currentRequest === null
                || $transaction === null
                || $currentRequest->completed_at !== null
                || ! $currentRequest->claimed_at?->equalTo($classificationRequest->claimed_at)) {
                return;
            }

            if ($this->hasAuthoritativeAssignment($transaction)) {
                $this->completeAsSuperseded($currentRequest);

                return;
            }

            if ($this->hasStaleInput($currentRequest, $transaction)) {
                $this->refreshForRevision($currentRequest, $transaction);

                return;
            }

            if ($currentRequest->attempt_count < 3) {
                $currentRequest->forceFill([
                    'claimed_at' => null,
                    'next_attempt_at' => now()->addSeconds(
                        $this->retryDelayInSeconds($currentRequest),
                    ),
                    'last_error_code' => $this->classifierError($outcome),
                ])->save();

                return;
            }

            $transaction->revision++;
            $transaction->save();

            CategoryAssignment::create([
                'user_id' => $transaction->user_id,
                'transaction_id' => $transaction->id,
                'category_id' => null,
                'previous_category_id' => $transaction->category_id,
                'source' => CategoryAssignmentProvenance::Ai,
                'transaction_revision' => $transaction->revision,
                'ai_classifier_version' => $this->aiClassifier->version(),
                'ai_confidence' => null,
                'ai_outcome' => $outcome,
                'ai_explanation' => $outcome === AiClassificationOutcome::Timeout
                    ? 'The AI classifier timed out after bounded retries.'
                    : 'The AI classifier remained unavailable after bounded retries.',
            ]);

            $currentRequest->forceFill([
                'completed_at' => now(),
                'terminal_outcome' => $outcome,
                'claimed_at' => null,
                'next_attempt_at' => null,
                'last_error_code' => $this->classifierError($outcome),
            ])->save();
        }, 3);
    }

    private function retryDelayInSeconds(AiClassificationRequest $classificationRequest): int
    {
        $baseDelay = $classificationRequest->attempt_count === 1 ? 60 : 300;
        $jitter = hexdec(substr(hash(
            'sha256',
            $classificationRequest->id.':'.$classificationRequest->attempt_count,
        ), 0, 2)) % 31;

        return $baseDelay + $jitter;
    }

    /** @return Collection<int, Category> */
    private function activeCategories(int $ownerId): Collection
    {
        return Category::query()
            ->where('user_id', $ownerId)
            ->whereNull('retired_at')
            ->where(fn ($query) => $query
                ->whereNull('parent_id')
                ->orWhereHas('parent', fn ($query) => $query->whereNull('retired_at')))
            ->with('parent:id,name')
            ->orderByRaw('lower(name)')
            ->get([
                'id',
                'parent_id',
                'name',
                'description',
                'examples',
            ])
            ->sortBy(fn (Category $category): string => $category->parent === null
                ? $category->name
                : $category->parent->name.' > '.$category->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return list<array{path: string, description: string|null, examples: list<string>}>
     */
    private function categoryGuidance(Collection $categories): array
    {
        return array_values($categories
            ->map(fn (Category $category): array => [
                'path' => $this->categoryPath($category),
                'description' => $category->description,
                'examples' => $category->examples,
            ])
            ->all());
    }

    private function categoryPath(Category $category): string
    {
        return $category->parent === null
            ? $category->name
            : $category->parent->name.' > '.$category->name;
    }
}

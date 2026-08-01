<?php

namespace App\Actions\Categorization;

use App\Actions\Integrations\ClassifyIntegrationFailure;
use App\Actions\Integrations\RecordIntegrationFailure;
use App\Actions\Integrations\RecordIntegrationRecovery;
use App\AiClassificationError;
use App\AiClassificationInput;
use App\AiClassificationOutcome;
use App\AiClassificationTaxonomyFingerprint;
use App\CategoryAssignmentProvenance;
use App\Contracts\AiClassifier;
use App\Exceptions\AiClassifierTimedOut;
use App\Exceptions\AiClassifierUnavailable;
use App\IntegrationService;
use App\IntegrationWorkType;
use App\MerchantNormalizer;
use App\Models\AiCategoryProposal;
use App\Models\AiClassificationRequest;
use App\Models\Category;
use App\Models\CategoryAssignment;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Lottery;
use Throwable;

class ClassifyTransactionWithAi
{
    public function __construct(
        private AiClassifier $aiClassifier,
        private MerchantNormalizer $merchantNormalizer,
        private AiClassificationTaxonomyFingerprint $taxonomyFingerprint,
        private EvaluateAiClassificationTrustGate $evaluateTrustGate,
        private ResolveAiClassificationValidationContext $resolveValidationContext,
        private ClassifyIntegrationFailure $classifyIntegrationFailure,
        private RecordIntegrationFailure $recordIntegrationFailure,
        private RecordIntegrationRecovery $recordIntegrationRecovery,
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
        $taxonomyFingerprint = $this->taxonomyFingerprint->make($categories);
        $validationContext = $this->resolveValidationContext->handle(
            ownerId: $classificationRequest->user_id,
            classifierVersion: $this->aiClassifier->version(),
            taxonomyFingerprint: $taxonomyFingerprint,
        );
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
        } catch (AiClassifierTimedOut $exception) {
            $this->recordClassifierFailure(
                $classificationRequest,
                AiClassificationOutcome::Timeout,
                $exception,
            );

            return;
        } catch (AiClassifierUnavailable $exception) {
            $this->recordClassifierFailure(
                $classificationRequest,
                AiClassificationOutcome::Unavailable,
                $exception,
            );

            return;
        }

        $offeredCategoryId = null;
        $proposedParentId = null;
        $proposalParentResolved = false;

        if ($result->categoryPath !== null) {
            $matchingCategories = $categories->filter(
                fn (Category $category): bool => $this->categoryPath($category) === $result->categoryPath,
            );

            if ($matchingCategories->count() === 1) {
                $offeredCategoryId = $matchingCategories->first()?->id;
            }
        }

        if ($result->categoryPath === null && $result->categoryProposal !== null) {
            if ($result->categoryProposal->parentCategoryPath === null) {
                $proposalParentResolved = true;
            } else {
                $matchingParents = $categories->filter(
                    fn (Category $category): bool => $category->parent_id === null
                        && $this->categoryPath($category) === $result->categoryProposal->parentCategoryPath,
                );

                if ($matchingParents->count() === 1) {
                    $proposedParentId = $matchingParents->first()?->id;
                    $proposalParentResolved = true;
                }
            }
        }

        DB::transaction(function () use (
            $classificationRequest,
            $offeredCategoryId,
            $result,
            $taxonomyFingerprint,
            $proposedParentId,
            $proposalParentResolved,
            $validationContext,
        ): void {
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
            $proposedParent = $proposedParentId === null
                ? null
                : Category::query()
                    ->where('user_id', $transaction->user_id)
                    ->whereKey($proposedParentId)
                    ->whereNull('parent_id')
                    ->whereNull('retired_at')
                    ->lockForUpdate()
                    ->first();
            $proposalIsMissing = $proposalParentResolved
                && $result->categoryProposal !== null
                && ($proposedParentId === null || $proposedParent !== null)
                && ! Category::query()
                    ->where('user_id', $transaction->user_id)
                    ->whereNull('retired_at')
                    ->whereRaw('lower(name) = lower(?)', [$result->categoryProposal->name])
                    ->when(
                        $proposedParentId === null,
                        fn ($query) => $query->whereNull('parent_id'),
                        fn ($query) => $query->where('parent_id', $proposedParentId),
                    )
                    ->exists();
            $currentCategories = $this->activeCategories($transaction->user_id);
            $currentTaxonomyFingerprint = $this->taxonomyFingerprint->make($currentCategories);
            $currentValidationContext = $this->resolveValidationContext->handle(
                ownerId: $transaction->user_id,
                classifierVersion: $this->aiClassifier->version(),
                taxonomyFingerprint: $currentTaxonomyFingerprint,
            );
            $trustContextIsCurrent = $currentValidationContext->revision === $validationContext->revision
                && $currentTaxonomyFingerprint === $taxonomyFingerprint;
            $trustGateIsOpen = $category !== null
                && $result->confidence >= 95
                && $trustContextIsCurrent
                && $this->evaluateTrustGate->handle(
                    ownerId: $transaction->user_id,
                    classifierVersion: $this->aiClassifier->version(),
                    taxonomyFingerprint: $taxonomyFingerprint,
                    validationContextRevision: $validationContext->revision,
                );
            $outcome = match (true) {
                $proposalIsMissing && $trustContextIsCurrent => AiClassificationOutcome::MissingCategory,
                $category === null => AiClassificationOutcome::InvalidCategory,
                $trustGateIsOpen => AiClassificationOutcome::High,
                $result->confidence >= 60 => AiClassificationOutcome::Medium,
                default => AiClassificationOutcome::LowConfidence,
            };
            $requiresReview = $outcome === AiClassificationOutcome::High
                ? Lottery::odds(1, 10)->choose()
                : true;
            $categoryId = $outcome === AiClassificationOutcome::Medium
                || $outcome === AiClassificationOutcome::High
                ? $category->id
                : null;
            $previousCategoryId = $transaction->category_id;
            $transaction->category_id = $categoryId;
            $transaction->category_assignment_provenance = $categoryId === null
                ? null
                : CategoryAssignmentProvenance::Ai;
            $transaction->revision++;
            $transaction->save();

            $assignment = CategoryAssignment::create([
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
                'ai_taxonomy_fingerprint' => $taxonomyFingerprint,
                'ai_validation_context_revision' => $validationContext->revision,
                'ai_requires_review' => $requiresReview,
            ]);

            if ($outcome === AiClassificationOutcome::MissingCategory) {
                AiCategoryProposal::query()->create([
                    'user_id' => $transaction->user_id,
                    'transaction_id' => $transaction->id,
                    'category_assignment_id' => $assignment->id,
                    'parent_id' => $proposedParent?->id,
                    'name' => $result->categoryProposal->name,
                    'description' => $result->categoryProposal->description,
                    'examples' => $result->categoryProposal->examples,
                ]);
            }

            $currentRequest->forceFill([
                'completed_at' => now(),
                'terminal_outcome' => $outcome,
                'claimed_at' => null,
                'next_attempt_at' => null,
                'last_error_code' => null,
            ])->save();
        }, 3);

        $this->recordRecovery($classificationRequest);
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

        $this->recordRecovery($classificationRequest);
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

        $this->recordRecovery($classificationRequest);
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
        Throwable $exception,
    ): void {
        DB::transaction(function () use ($classificationRequest, $outcome, $exception): void {
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

            $failureKind = $this->classifyIntegrationFailure->handle($exception);
            $decision = $this->recordIntegrationFailure->handle(
                owner: $currentRequest->owner,
                integration: IntegrationService::Ai,
                workType: IntegrationWorkType::AiClassification,
                workId: (string) $currentRequest->id,
                sourceIdentity: 'ai:transaction:'.$currentRequest->transaction_id
                    .':revision:'.$currentRequest->expected_transaction_revision,
                failureKind: $failureKind,
                errorCode: $this->classifierError($outcome)->value,
            );

            if ($decision->shouldRetry) {
                $currentRequest->forceFill([
                    'claimed_at' => null,
                    'next_attempt_at' => $decision->nextAttemptAt,
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

    private function recordRecovery(AiClassificationRequest $classificationRequest): void
    {
        $this->recordIntegrationRecovery->handle(
            owner: $classificationRequest->owner,
            integration: IntegrationService::Ai,
            workType: IntegrationWorkType::AiClassification,
            workId: (string) $classificationRequest->id,
        );
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

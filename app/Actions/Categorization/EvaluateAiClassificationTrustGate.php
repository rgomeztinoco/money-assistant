<?php

namespace App\Actions\Categorization;

use App\AiClassificationOutcome;
use App\CategoryAssignmentProvenance;
use App\Models\CategoryAssignment;

final class EvaluateAiClassificationTrustGate
{
    public function handle(
        int $ownerId,
        string $classifierVersion,
        string $taxonomyFingerprint,
    ): bool {
        $qualifyingReviews = CategoryAssignment::query()
            ->where('user_id', $ownerId)
            ->where('source', CategoryAssignmentProvenance::Ai)
            ->where('ai_classifier_version', $classifierVersion)
            ->where('ai_taxonomy_fingerprint', $taxonomyFingerprint)
            ->where('ai_confidence', '>=', 95)
            ->whereIn('ai_outcome', [
                AiClassificationOutcome::Medium->value,
                AiClassificationOutcome::High->value,
            ])
            ->where('ai_requires_review', true)
            ->whereNotNull('ai_reviewed_at');

        $reviewCount = (clone $qualifyingReviews)->count();

        if ($reviewCount < 50) {
            return false;
        }

        $unchangedApprovalCount = (clone $qualifyingReviews)
            ->where('ai_approved_unchanged', true)
            ->count();

        if ($unchangedApprovalCount * 100 < $reviewCount * 95) {
            return false;
        }

        $latestReviews = $qualifyingReviews
            ->latest('ai_reviewed_at')
            ->latest('id')
            ->limit(50)
            ->pluck('ai_approved_unchanged');

        return $latestReviews
            ->filter(fn (bool $approvedUnchanged): bool => $approvedUnchanged)
            ->count() * 100 >= 50 * 95;
    }
}

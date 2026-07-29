<?php

namespace App\Actions\Categorization;

use App\Models\AiClassificationValidationContext;
use Illuminate\Support\Facades\DB;

final class ResolveAiClassificationValidationContext
{
    public function handle(
        int $ownerId,
        string $classifierVersion,
        string $taxonomyFingerprint,
    ): AiClassificationValidationContext {
        return DB::transaction(function () use ($ownerId, $classifierVersion, $taxonomyFingerprint): AiClassificationValidationContext {
            AiClassificationValidationContext::query()->firstOrCreate(
                ['user_id' => $ownerId],
                [
                    'classifier_version' => $classifierVersion,
                    'taxonomy_fingerprint' => $taxonomyFingerprint,
                ],
            );

            $context = AiClassificationValidationContext::query()
                ->where('user_id', $ownerId)
                ->lockForUpdate()
                ->sole();

            if ($context->taxonomy_fingerprint === null) {
                $context->forceFill([
                    'classifier_version' => $classifierVersion,
                    'taxonomy_fingerprint' => $taxonomyFingerprint,
                ])->save();
            } elseif ($context->classifier_version !== $classifierVersion
                || $context->taxonomy_fingerprint !== $taxonomyFingerprint) {
                $context->forceFill([
                    'revision' => $context->revision + 1,
                    'classifier_version' => $classifierVersion,
                    'taxonomy_fingerprint' => $taxonomyFingerprint,
                ])->save();
            }

            return $context;
        }, 3);
    }
}

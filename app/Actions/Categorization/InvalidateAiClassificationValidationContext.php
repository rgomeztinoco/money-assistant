<?php

namespace App\Actions\Categorization;

use App\Models\AiClassificationValidationContext;
use App\Models\User;

final class InvalidateAiClassificationValidationContext
{
    public function handle(User $owner): void
    {
        $context = AiClassificationValidationContext::query()
            ->whereBelongsTo($owner, 'owner')
            ->lockForUpdate()
            ->first();

        if ($context === null) {
            return;
        }

        $context->forceFill([
            'revision' => $context->revision + 1,
            'taxonomy_fingerprint' => null,
        ])->save();
    }
}

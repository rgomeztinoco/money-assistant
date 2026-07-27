<?php

namespace App\Actions\Categorization;

use App\Models\LearnedRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RetireLearnedRule
{
    public function handle(User $owner, int $ruleId, int $expectedRevision): LearnedRule
    {
        return DB::transaction(function () use ($owner, $ruleId, $expectedRevision): LearnedRule {
            User::query()->whereKey($owner->id)->lockForUpdate()->sole();

            $rule = LearnedRule::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($ruleId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($rule->revision !== $expectedRevision) {
                throw ValidationException::withMessages([
                    'expected_revision' => 'This Learned Rule changed after you reviewed it.',
                ]);
            }

            if ($rule->retired_at === null) {
                $rule->retired_at = now();
                $rule->save();
            }

            return $rule;
        }, 3);
    }
}

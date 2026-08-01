<?php

namespace App\Actions\NotificationIngestion;

use App\Models\ParserProfile;
use App\Models\User;
use App\SpendingNotificationProcessingOutcome;
use Illuminate\Database\Eloquent\Builder;

final class ReadParserProfileHealthSummary
{
    /**
     * @return array{
     *     healthy_count: int,
     *     degraded_count: int,
     *     alerts: list<array{profile_id: int, profile_name: string, kind: 'security'|'drift', count: int}>
     * }
     */
    public function handle(User $owner): array
    {
        $profiles = ParserProfile::query()
            ->whereBelongsTo($owner, 'owner')
            ->withCount([
                'references as security_failure_count' => fn (Builder $query): Builder => $query
                    ->where('processing_outcome', SpendingNotificationProcessingOutcome::AuthenticationFailed->value),
                'references as drift_failure_count' => fn (Builder $query): Builder => $query
                    ->whereIn('processing_outcome', [
                        SpendingNotificationProcessingOutcome::Unsupported->value,
                        SpendingNotificationProcessingOutcome::Failed->value,
                    ]),
            ])
            ->latest()
            ->get(['id', 'name']);
        $alerts = [];
        $healthyCount = 0;

        foreach ($profiles as $profile) {
            $securityFailureCount = (int) $profile->getAttribute('security_failure_count');
            $driftFailureCount = (int) $profile->getAttribute('drift_failure_count');

            if ($securityFailureCount === 0 && $driftFailureCount === 0) {
                $healthyCount++;
            }

            if ($securityFailureCount > 0) {
                $alerts[] = [
                    'profile_id' => $profile->id,
                    'profile_name' => $profile->name,
                    'kind' => 'security',
                    'count' => $securityFailureCount,
                ];
            }

            if ($driftFailureCount > 0) {
                $alerts[] = [
                    'profile_id' => $profile->id,
                    'profile_name' => $profile->name,
                    'kind' => 'drift',
                    'count' => $driftFailureCount,
                ];
            }
        }

        return [
            'healthy_count' => $healthyCount,
            'degraded_count' => $profiles->count() - $healthyCount,
            'alerts' => $alerts,
        ];
    }
}

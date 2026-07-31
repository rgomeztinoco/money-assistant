<?php

namespace App\Actions\NotificationIngestion;

use App\Models\ParserProfile;
use App\Models\SpendingNotificationReference;
use App\Models\User;
use App\SpendingNotificationProcessingOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ReadParserProfileHealth
{
    /**
     * @return array{
     *     profiles: list<array{
     *         id: int,
     *         name: string,
     *         current_version: int,
     *         enabled_at: string|null,
     *         health: array{
     *             state: 'healthy'|'degraded',
     *             counts: array{
     *                 created: int,
     *                 created_with_review: int,
     *                 unsupported: int,
     *                 failed: int,
     *                 ignored: int
     *             },
     *             last_success: string|null,
     *             oldest_unresolved_failure: string|null
     *         }
     *     }>,
     *     alerts: list<array{
     *         profile_id: int,
     *         profile_name: string|null,
     *         kind: 'security'|'drift',
     *         count: int,
     *         oldest_failure: string|null,
     *         references: list<array{
     *             id: int,
     *             discovery_id: int|null,
     *             outcome: string,
     *             created_at: string|null
     *         }>
     *     }>
     * }
     */
    public function handle(User $owner): array
    {
        $failureOutcomes = SpendingNotificationProcessingOutcome::failureValues();
        $profiles = ParserProfile::query()
            ->whereBelongsTo($owner, 'owner')
            ->withCount([
                'references as created_count' => fn (Builder $query): Builder => $query
                    ->where('processing_outcome', SpendingNotificationProcessingOutcome::Created->value),
                'references as created_with_review_count' => fn (Builder $query): Builder => $query
                    ->where('processing_outcome', SpendingNotificationProcessingOutcome::CreatedWithReview->value),
                'references as unsupported_count' => fn (Builder $query): Builder => $query
                    ->where('processing_outcome', SpendingNotificationProcessingOutcome::Unsupported->value),
                'references as failed_count' => fn (Builder $query): Builder => $query
                    ->whereIn('processing_outcome', [
                        SpendingNotificationProcessingOutcome::AuthenticationFailed->value,
                        SpendingNotificationProcessingOutcome::Failed->value,
                    ]),
                'references as ignored_count' => fn (Builder $query): Builder => $query
                    ->where('processing_outcome', SpendingNotificationProcessingOutcome::Ignored->value),
            ])
            ->withMax([
                'references as last_success_at' => fn (Builder $query): Builder => $query
                    ->whereIn(
                        'processing_outcome',
                        SpendingNotificationProcessingOutcome::successValues(),
                    ),
            ], 'last_attempted_at')
            ->withMin([
                'references as oldest_unresolved_failure_at' => fn (Builder $query): Builder => $query
                    ->whereIn('processing_outcome', $failureOutcomes),
            ], 'created_at')
            ->latest()
            ->get(['id', 'name', 'current_version', 'enabled_at']);
        $profileNames = $profiles->pluck('name', 'id');
        $alerts = array_values(SpendingNotificationReference::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereIn('processing_outcome', $failureOutcomes)
            ->whereNotNull('parser_profile_version_id')
            ->with('profileVersion:id,parser_profile_id')
            ->oldest()
            ->get([
                'id',
                'parser_profile_version_id',
                'gmail_message_discovery_id',
                'processing_outcome',
                'created_at',
            ])
            ->groupBy(function (SpendingNotificationReference $reference): string {
                $kind = $reference->processing_outcome === SpendingNotificationProcessingOutcome::AuthenticationFailed->value
                    ? 'security'
                    : 'drift';

                return $reference->profileVersion->parser_profile_id.':'.$kind;
            })
            ->map(fn (Collection $references, string $key): array => $this->alert(
                $references,
                $key,
                $profileNames,
            ))
            ->values()
            ->all());

        return [
            'profiles' => array_values($profiles
                ->map(fn (ParserProfile $profile): array => [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'current_version' => $profile->current_version,
                    'enabled_at' => $profile->enabled_at?->toIso8601String(),
                    'health' => [
                        'state' => $profile->getAttribute('oldest_unresolved_failure_at') === null
                            ? 'healthy'
                            : 'degraded',
                        'counts' => [
                            'created' => (int) $profile->getAttribute('created_count'),
                            'created_with_review' => (int) $profile->getAttribute('created_with_review_count'),
                            'unsupported' => (int) $profile->getAttribute('unsupported_count'),
                            'failed' => (int) $profile->getAttribute('failed_count'),
                            'ignored' => (int) $profile->getAttribute('ignored_count'),
                        ],
                        'last_success' => $this->timestamp(
                            $profile->getAttribute('last_success_at'),
                        ),
                        'oldest_unresolved_failure' => $this->timestamp(
                            $profile->getAttribute('oldest_unresolved_failure_at'),
                        ),
                    ],
                ])
                ->all()),
            'alerts' => $alerts,
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        return is_string($value)
            ? CarbonImmutable::parse($value)->toIso8601String()
            : null;
    }

    /**
     * @param  Collection<int, SpendingNotificationReference>  $references
     * @param  Collection<int|string, mixed>  $profileNames
     * @return array{
     *     profile_id: int,
     *     profile_name: string|null,
     *     kind: 'security'|'drift',
     *     count: int,
     *     oldest_failure: string|null,
     *     references: list<array{
     *         id: int,
     *         discovery_id: int|null,
     *         outcome: string,
     *         created_at: string|null
     *     }>
     * }
     */
    private function alert(
        Collection $references,
        string $key,
        Collection $profileNames,
    ): array {
        [$profileId, $groupKind] = explode(':', $key, 2);
        $profileName = $profileNames->get((int) $profileId);
        $kind = $groupKind === 'security' ? 'security' : 'drift';

        return [
            'profile_id' => (int) $profileId,
            'profile_name' => is_string($profileName) ? $profileName : null,
            'kind' => $kind,
            'count' => $references->count(),
            'oldest_failure' => $references->first()?->created_at?->toIso8601String(),
            'references' => array_values($references
                ->map(fn (SpendingNotificationReference $reference): array => [
                    'id' => $reference->id,
                    'discovery_id' => $reference->gmail_message_discovery_id,
                    'outcome' => $reference->processing_outcome,
                    'created_at' => $reference->created_at?->toIso8601String(),
                ])
                ->all()),
        ];
    }
}

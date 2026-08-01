<?php

namespace App\Actions\Integrations;

use App\IntegrationFailureKind;
use App\IntegrationRetryDecision;
use App\IntegrationService;
use App\IntegrationWorkType;
use App\Models\IntegrationIncident;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecordIntegrationFailure
{
    public function handle(
        User $owner,
        IntegrationService $integration,
        IntegrationWorkType $workType,
        string $workId,
        string $sourceIdentity,
        IntegrationFailureKind $failureKind,
        string $errorCode,
    ): IntegrationRetryDecision {
        return DB::transaction(function () use (
            $owner,
            $integration,
            $workType,
            $workId,
            $sourceIdentity,
            $failureKind,
            $errorCode,
        ): IntegrationRetryDecision {
            $attributes = [
                'user_id' => $owner->id,
                'integration' => $integration->value,
                'work_type' => $workType->value,
                'work_id' => $workId,
            ];

            IntegrationIncident::query()->insertOrIgnore([
                ...$attributes,
                'source_identity' => $sourceIdentity,
                'failure_kind' => $failureKind->value,
                'last_error_code' => $errorCode,
                'first_failed_at' => now(),
                'last_failed_at' => now(),
                'visible_at' => now()->addMinutes(15),
                'retry_until' => now()->addDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $incident = IntegrationIncident::query()
                ->where($attributes)
                ->lockForUpdate()
                ->sole();

            if ($incident->recovered_at !== null) {
                $incident->forceFill([
                    'source_identity' => $sourceIdentity,
                    'attempt_count' => 0,
                    'first_failed_at' => now(),
                    'visible_at' => now()->addMinutes(15),
                    'retry_until' => now()->addDay(),
                    'parked_at' => null,
                    'acknowledged_at' => null,
                    'recovered_at' => null,
                ]);
            }

            $attemptCount = $incident->attempt_count + 1;
            $shouldRetry = $failureKind->isTransient()
                && now()->isBefore($incident->retry_until);
            $nextAttemptAt = $shouldRetry
                ? $this->nextAttemptAt($incident, $attemptCount)
                : null;

            $incident->forceFill([
                'failure_kind' => $failureKind,
                'last_error_code' => $errorCode,
                'attempt_count' => $attemptCount,
                'last_failed_at' => now(),
                'next_attempt_at' => $nextAttemptAt,
                'parked_at' => $shouldRetry ? null : now(),
            ])->save();

            return new IntegrationRetryDecision(
                incident: $incident,
                shouldRetry: $shouldRetry,
                nextAttemptAt: $nextAttemptAt,
            );
        }, 3);
    }

    private function nextAttemptAt(
        IntegrationIncident $incident,
        int $attemptCount,
    ): CarbonImmutable {
        $baseDelayInSeconds = match ($attemptCount) {
            1 => 60,
            2 => 300,
            3 => 900,
            default => 3600,
        };
        $jitterInSeconds = hexdec(Str::substr(hash(
            'sha256',
            $incident->source_identity.':'.$attemptCount,
        ), 0, 2)) % 31;
        $nextAttemptAt = CarbonImmutable::now()
            ->addSeconds($baseDelayInSeconds + $jitterInSeconds);

        return $nextAttemptAt->isAfter($incident->retry_until)
            ? $incident->retry_until
            : $nextAttemptAt;
    }
}

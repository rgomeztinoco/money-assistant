<?php

namespace App\Actions\Reporting;

use App\Actions\Integrations\ClassifyIntegrationFailure;
use App\Actions\Integrations\RecordIntegrationFailure;
use App\Actions\Integrations\RecordIntegrationRecovery;
use App\Actions\Reminders\ResolveReminder;
use App\Actions\Reminders\ScheduleReminder;
use App\Contracts\BcrpData;
use App\ExactInteger;
use App\IntegrationFailureKind;
use App\Integrations\BcrpData\BcrpExchangeRateObservation;
use App\IntegrationService;
use App\IntegrationWorkType;
use App\Models\DailyExchangeRate;
use App\Models\DailyExchangeRateSeedRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use UnexpectedValueException;

final class SeedDailyExchangeRateFromBcrpData
{
    private const MAX_ATTEMPTS = 32;

    private const MAX_UNEXPECTED_ATTEMPTS = 8;

    private const MAX_MISSING_OBSERVATIONS = 3;

    private const MAX_TRANSPORT_FAILURES = 5;

    public function __construct(
        private BcrpData $bcrpData,
        private ScheduleReminder $scheduleReminder,
        private ResolveReminder $resolveReminder,
        private IsDailyExchangeRateStillRequired $isDailyExchangeRateStillRequired,
        private ClassifyIntegrationFailure $classifyIntegrationFailure,
        private RecordIntegrationFailure $recordIntegrationFailure,
        private RecordIntegrationRecovery $recordIntegrationRecovery,
    ) {}

    public function handle(int $seedRequestId): void
    {
        $seedRequest = DB::transaction(function () use ($seedRequestId): ?DailyExchangeRateSeedRequest {
            $seedRequest = DailyExchangeRateSeedRequest::query()->lockForUpdate()->find($seedRequestId);

            if ($seedRequest === null
                || $seedRequest->completed_at !== null
                || ($seedRequest->next_attempt_at !== null && $seedRequest->next_attempt_at->isFuture())
                || ($seedRequest->claimed_at !== null && $seedRequest->claimed_at->isAfter(now()->subMinute()))) {
                return null;
            }

            if (! $this->isDailyExchangeRateStillRequired->handle($seedRequest)) {
                $seedRequest->forceFill([
                    'completed_at' => now(),
                    'owner_entry_required_at' => null,
                    'retrieval_failed_at' => null,
                    'queued_at' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'last_error_code' => 'no_longer_required',
                ])->save();

                $this->resolveOwnerEntryReminder(
                    seedRequest: $seedRequest,
                    domainAction: 'daily_exchange_rate.no_longer_required',
                );

                return $seedRequest;
            }

            if (DailyExchangeRate::query()
                ->where('user_id', $seedRequest->user_id)
                ->whereDate('applicable_on', $seedRequest->applicable_on)
                ->exists()) {
                $seedRequest->forceFill([
                    'completed_at' => now(),
                    'owner_entry_required_at' => null,
                    'retrieval_failed_at' => null,
                    'queued_at' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'last_error_code' => null,
                ])->save();

                $this->resolveOwnerEntryReminder($seedRequest, 'daily_exchange_rate.entered');

                return $seedRequest;
            }

            if ($seedRequest->owner_entry_required_at !== null) {
                return null;
            }

            if ($seedRequest->retrieval_failed_at !== null) {
                return null;
            }

            $hasActiveTransientIncident = $seedRequest->owner->integrationIncidents()
                ->where('integration', IntegrationService::Bcrp)
                ->where('work_type', IntegrationWorkType::DailyExchangeRateSeed)
                ->where('work_id', (string) $seedRequest->id)
                ->where('failure_kind', IntegrationFailureKind::Transient)
                ->whereNull('recovered_at')
                ->exists();

            if ($seedRequest->attempt_count >= self::MAX_ATTEMPTS
                || ($seedRequest->attempt_count >= self::MAX_UNEXPECTED_ATTEMPTS
                    && ! $hasActiveTransientIncident)) {
                $seedRequest->forceFill([
                    'retrieval_failed_at' => now(),
                    'queued_at' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'last_error_code' => 'retry_exhausted',
                ])->save();

                return $seedRequest;
            }

            $seedRequest->forceFill([
                'attempt_count' => $seedRequest->attempt_count + 1,
                'queued_at' => null,
                'claimed_at' => now(),
                'last_attempted_at' => now(),
            ])->save();

            return $seedRequest;
        });

        if ($seedRequest === null) {
            return;
        }

        if ($seedRequest->completed_at !== null) {
            $this->recordRecovery($seedRequest);

            return;
        }

        if ($seedRequest->owner_entry_required_at !== null) {
            $this->scheduleOwnerEntryReminder($seedRequest->id);

            return;
        }

        if ($seedRequest->retrieval_failed_at !== null) {
            return;
        }

        try {
            $observation = $this->bcrpData->findObservation($seedRequest->applicable_on);

            if ($observation === null) {
                $this->recordUnavailable(
                    seedRequest: $seedRequest,
                    errorCode: 'observation_unavailable',
                    missingObservation: $this->isRecentBusinessDay($seedRequest),
                    retryable: $this->isRecentBusinessDay($seedRequest),
                    ownerEntryOnFailure: true,
                );

                return;
            }

            if ($this->shouldRetryRecentBusinessDay($seedRequest, $observation)) {
                $this->recordUnavailable(
                    seedRequest: $seedRequest,
                    errorCode: 'recent_observation_unavailable',
                    missingObservation: true,
                    ownerEntryOnFailure: true,
                );

                return;
            }

            $this->storeObservation($seedRequest, $observation);
            $this->recordRecovery($seedRequest);
        } catch (ConnectionException|InvalidArgumentException|RequestException|UnexpectedValueException $exception) {
            $this->recordIntegrationFailure($seedRequest, $exception);
        }
    }

    private function recordIntegrationFailure(
        DailyExchangeRateSeedRequest $seedRequest,
        \Throwable $exception,
    ): void {
        $failureKind = $this->classifyIntegrationFailure->handle($exception);
        $decision = $this->recordIntegrationFailure->handle(
            owner: $seedRequest->owner,
            integration: IntegrationService::Bcrp,
            workType: IntegrationWorkType::DailyExchangeRateSeed,
            workId: (string) $seedRequest->id,
            sourceIdentity: 'bcrp:PD04638PD:'.$seedRequest->applicable_on->toDateString(),
            failureKind: $failureKind,
            errorCode: $failureKind->isTransient()
                ? 'bcrp_request_failed'
                : 'bcrp_response_invalid',
        );

        DB::transaction(function () use ($seedRequest, $failureKind, $decision): void {
            $current = DailyExchangeRateSeedRequest::query()
                ->lockForUpdate()
                ->find($seedRequest->id);

            if ($current === null
                || $current->completed_at !== null
                || $current->owner_entry_required_at !== null
                || $current->retrieval_failed_at !== null
                || ! $current->claimed_at?->equalTo($seedRequest->claimed_at)) {
                return;
            }

            $current->forceFill([
                'queued_at' => null,
                'claimed_at' => null,
                'transport_failure_count' => $failureKind->isTransient()
                    ? min(self::MAX_TRANSPORT_FAILURES, $current->transport_failure_count + 1)
                    : $current->transport_failure_count,
                'next_attempt_at' => $decision->nextAttemptAt,
                'retrieval_failed_at' => $decision->shouldRetry ? null : now(),
                'last_error_code' => $decision->incident->last_error_code,
            ])->save();
        });
    }

    private function recordRecovery(DailyExchangeRateSeedRequest $seedRequest): void
    {
        $this->recordIntegrationRecovery->handle(
            owner: $seedRequest->owner,
            integration: IntegrationService::Bcrp,
            workType: IntegrationWorkType::DailyExchangeRateSeed,
            workId: (string) $seedRequest->id,
        );
    }

    private function storeObservation(
        DailyExchangeRateSeedRequest $seedRequest,
        BcrpExchangeRateObservation $observation,
    ): void {
        DB::transaction(function () use ($seedRequest, $observation): void {
            $current = DailyExchangeRateSeedRequest::query()
                ->lockForUpdate()
                ->find($seedRequest->id);

            if ($current === null
                || $current->completed_at !== null
                || $current->owner_entry_required_at !== null
                || $current->retrieval_failed_at !== null
                || ! $current->claimed_at?->equalTo($seedRequest->claimed_at)) {
                return;
            }

            if (! $this->isDailyExchangeRateStillRequired->handle($current)) {
                $current->forceFill([
                    'completed_at' => now(),
                    'queued_at' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'last_error_code' => 'no_longer_required',
                ])->save();
                $this->resolveOwnerEntryReminder(
                    seedRequest: $current,
                    domainAction: 'daily_exchange_rate.no_longer_required',
                );

                return;
            }

            DailyExchangeRate::query()->insertOrIgnore([
                'user_id' => $current->user_id,
                'applicable_on' => $current->applicable_on->toDateString(),
                'pen_per_usd_scaled' => $this->scaleRate($observation->value),
                'owner_managed_at' => null,
                'source' => 'bcrp_data',
                'source_series' => 'PD04638PD',
                'source_observed_on' => $observation->observedOn->toDateString(),
                'source_retrieved_at' => $observation->retrievedAt,
                'source_value' => $observation->value,
                'source_precision' => $observation->sourcePrecision,
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $current->forceFill([
                'completed_at' => now(),
                'queued_at' => null,
                'claimed_at' => null,
                'next_attempt_at' => null,
                'last_error_code' => null,
            ])->save();
        });
    }

    private function recordUnavailable(
        DailyExchangeRateSeedRequest $seedRequest,
        string $errorCode,
        bool $missingObservation = false,
        bool $retryable = true,
        bool $ownerEntryOnFailure = false,
    ): void {
        $missingObservationCount = $seedRequest->missing_observation_count + ($missingObservation ? 1 : 0);
        $shouldRetry = $retryable
            && $seedRequest->attempt_count < self::MAX_ATTEMPTS
            && $missingObservationCount < self::MAX_MISSING_OBSERVATIONS
            && $seedRequest->transport_failure_count < self::MAX_TRANSPORT_FAILURES;

        DB::transaction(function () use (
            $seedRequest,
            $missingObservationCount,
            $shouldRetry,
            $ownerEntryOnFailure,
            $errorCode,
        ): void {
            $current = DailyExchangeRateSeedRequest::query()
                ->lockForUpdate()
                ->find($seedRequest->id);

            if ($current === null
                || $current->completed_at !== null
                || $current->owner_entry_required_at !== null
                || $current->retrieval_failed_at !== null
                || ! $current->claimed_at?->equalTo($seedRequest->claimed_at)) {
                return;
            }

            if (! $this->isDailyExchangeRateStillRequired->handle($current)) {
                $current->forceFill([
                    'completed_at' => now(),
                    'queued_at' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'last_error_code' => 'no_longer_required',
                ])->save();
                $this->resolveOwnerEntryReminder(
                    seedRequest: $current,
                    domainAction: 'daily_exchange_rate.no_longer_required',
                );

                return;
            }

            $current->forceFill([
                'queued_at' => null,
                'claimed_at' => null,
                'missing_observation_count' => $missingObservationCount,
                'next_attempt_at' => $shouldRetry ? $this->nextRetryAt($seedRequest) : null,
                'owner_entry_required_at' => ! $shouldRetry && $ownerEntryOnFailure ? now() : null,
                'retrieval_failed_at' => ! $shouldRetry && ! $ownerEntryOnFailure ? now() : null,
                'last_error_code' => $errorCode,
            ])->save();

            if (! $shouldRetry && $ownerEntryOnFailure) {
                $this->scheduleOwnerEntryReminder($current->id);
            }
        });
    }

    private function nextRetryAt(DailyExchangeRateSeedRequest $seedRequest): CarbonImmutable
    {
        $backoffHours = match ($seedRequest->attempt_count) {
            1 => 1,
            2 => 4,
            3 => 12,
            default => 24,
        };
        $jitterSeconds = hexdec(substr(hash(
            'sha256',
            $seedRequest->id.':'.$seedRequest->attempt_count,
        ), 0, 3)) % 601;

        return CarbonImmutable::now()->addHours($backoffHours)->addSeconds($jitterSeconds);
    }

    private function scheduleOwnerEntryReminder(int $seedRequestId): void
    {
        DB::transaction(function () use ($seedRequestId): void {
            $seedRequest = DailyExchangeRateSeedRequest::query()
                ->with('owner')
                ->lockForUpdate()
                ->find($seedRequestId);

            if ($seedRequest === null
                || $seedRequest->owner_entry_required_at === null
                || $seedRequest->reminder_id !== null) {
                return;
            }

            $reminder = $this->scheduleReminder->handle(
                owner: $seedRequest->owner,
                subject: 'Enter the Daily Exchange Rate for '.$seedRequest->applicable_on->toDateString(),
                scheduledFor: now(),
            );

            $seedRequest->forceFill(['reminder_id' => $reminder->id])->save();
        });
    }

    private function shouldRetryRecentBusinessDay(
        DailyExchangeRateSeedRequest $seedRequest,
        BcrpExchangeRateObservation $observation,
    ): bool {
        if ($seedRequest->missing_observation_count >= self::MAX_MISSING_OBSERVATIONS - 1
            || $observation->observedOn->toDateString() === $seedRequest->applicable_on->toDateString()) {
            return false;
        }

        return $this->isRecentBusinessDay($seedRequest);
    }

    private function isRecentBusinessDay(DailyExchangeRateSeedRequest $seedRequest): bool
    {

        $applicableOn = CarbonImmutable::parse(
            $seedRequest->applicable_on->toDateString(),
            'America/Lima',
        )->startOfDay();
        $today = now('America/Lima')->startOfDay();

        return $applicableOn->isWeekday()
            && $applicableOn->betweenIncluded($today->subDays(4), $today);
    }

    private function resolveOwnerEntryReminder(
        DailyExchangeRateSeedRequest $seedRequest,
        string $domainAction,
    ): void {
        if ($seedRequest->reminder_id === null || $seedRequest->reminder?->resolved_at !== null) {
            return;
        }

        $this->resolveReminder->handle(
            owner: $seedRequest->owner,
            reminderId: $seedRequest->reminder_id,
            domainAction: $domainAction,
            idempotencyKey: $seedRequest->resolution_idempotency_key,
        );
    }

    private function scaleRate(string $rate): int
    {
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d+))?$/D', $rate, $matches) !== 1) {
            throw new InvalidArgumentException('The BCRP rate must be a decimal string.');
        }

        $fraction = $matches[2] ?? '';
        $scaled = ltrim($matches[1].str_pad(substr($fraction, 0, 6), 6, '0'), '0');
        $scaledInteger = ExactInteger::from($scaled === '' ? '0' : $scaled);

        if (isset($fraction[6]) && $fraction[6] >= '5') {
            $scaledInteger = $scaledInteger->add(ExactInteger::from(1));
        }

        if ($scaledInteger->compare(ExactInteger::from(0)) <= 0
            || $scaledInteger->compare(ExactInteger::from(PHP_INT_MAX)) > 0) {
            throw new InvalidArgumentException('The BCRP rate is outside the supported range.');
        }

        return (int) $scaledInteger->value();
    }
}

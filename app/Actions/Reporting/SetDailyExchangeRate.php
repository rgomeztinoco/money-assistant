<?php

namespace App\Actions\Reporting;

use App\Actions\Reminders\ResolveReminder;
use App\ExactInteger;
use App\Exceptions\StaleDailyExchangeRateRevision;
use App\Models\DailyExchangeRate;
use App\Models\DailyExchangeRateSeedRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SetDailyExchangeRate
{
    public function __construct(private ResolveReminder $resolveReminder) {}

    public function handle(
        User $owner,
        CarbonImmutable $applicableOn,
        string $penPerUsd,
        ?int $rateId = null,
        ?int $expectedRevision = null,
    ): DailyExchangeRate {
        $scaledRate = $this->scaleRate($penPerUsd);

        try {
            $rate = DB::transaction(function () use ($owner, $applicableOn, $scaledRate, $rateId, $expectedRevision): DailyExchangeRate {
                if ($rateId === null) {
                    $rate = DailyExchangeRate::query()->create([
                        'user_id' => $owner->getKey(),
                        'applicable_on' => $applicableOn,
                        'pen_per_usd_scaled' => $scaledRate,
                        'owner_managed_at' => now(),
                    ]);
                } else {
                    $rate = DailyExchangeRate::query()
                        ->whereBelongsTo($owner, 'owner')
                        ->whereKey($rateId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($expectedRevision === null || $rate->revision !== $expectedRevision) {
                        throw new StaleDailyExchangeRateRevision;
                    }

                    $rate->fill([
                        'pen_per_usd_scaled' => $scaledRate,
                        'owner_managed_at' => now(),
                        'source' => null,
                        'source_series' => null,
                        'source_observed_on' => null,
                        'source_retrieved_at' => null,
                        'source_value' => null,
                        'source_precision' => null,
                        'revision' => $rate->revision + 1,
                    ])->save();
                }

                $this->completeSeedRequest($owner, $applicableOn);

                return $rate;
            }, 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'applicable_on' => 'A Daily Exchange Rate already exists for this date.',
            ]);
        }

        return $rate;
    }

    private function completeSeedRequest(User $owner, CarbonImmutable $applicableOn): void
    {
        $seedRequest = DailyExchangeRateSeedRequest::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereDate('applicable_on', $applicableOn)
            ->lockForUpdate()
            ->first();

        if ($seedRequest === null) {
            return;
        }

        $seedRequest->forceFill([
            'completed_at' => $seedRequest->completed_at ?? now(),
            'owner_entry_required_at' => null,
            'retrieval_failed_at' => null,
            'next_attempt_at' => null,
            'queued_at' => null,
            'claimed_at' => null,
            'last_error_code' => null,
        ])->save();

        if ($seedRequest->reminder_id === null || $seedRequest->reminder?->resolved_at !== null) {
            return;
        }

        $this->resolveReminder->handle(
            owner: $owner,
            reminderId: $seedRequest->reminder_id,
            domainAction: 'daily_exchange_rate.entered',
            idempotencyKey: $seedRequest->resolution_idempotency_key,
        );
    }

    private function scaleRate(string $penPerUsd): int
    {
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,6}))?$/D', $penPerUsd, $matches) !== 1) {
            throw new InvalidArgumentException('The PEN per USD rate must be a decimal with at most six places.');
        }

        $scaled = ltrim($matches[1].str_pad($matches[2] ?? '', 6, '0'), '0');
        $scaled = $scaled === '' ? '0' : $scaled;

        if (
            ExactInteger::from($scaled)->compare(ExactInteger::from(0)) <= 0
            || ExactInteger::from($scaled)->compare(ExactInteger::from(PHP_INT_MAX)) > 0
        ) {
            throw ValidationException::withMessages([
                'pen_per_usd' => 'The PEN per USD rate is outside the supported range.',
            ]);
        }

        return (int) $scaled;
    }
}

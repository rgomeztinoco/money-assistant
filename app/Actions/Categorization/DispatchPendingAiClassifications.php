<?php

namespace App\Actions\Categorization;

use App\Jobs\ClassifyTransaction;
use App\Models\AiClassificationRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DispatchPendingAiClassifications
{
    public function handle(): void
    {
        /** @var Collection<int, int> $classificationRequestIds */
        $classificationRequestIds = DB::transaction(function (): Collection {
            $classificationRequests = AiClassificationRequest::query()
                ->whereNull('completed_at')
                ->where(fn ($query) => $query
                    ->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now()))
                ->where(fn ($query) => $query
                    ->whereNull('queued_at')
                    ->orWhere('queued_at', '<=', now()->subMinutes(2)))
                ->where(fn ($query) => $query
                    ->whereNull('claimed_at')
                    ->orWhere('claimed_at', '<=', now()->subMinute()))
                ->orderBy('next_attempt_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->limit(100)
                ->get();

            $classificationRequests->each(function (AiClassificationRequest $classificationRequest): void {
                $classificationRequest->forceFill(['queued_at' => now()])->save();
            });

            return $classificationRequests->pluck('id');
        });

        $classificationRequestIds->each(
            fn (int $classificationRequestId) => ClassifyTransaction::dispatch(
                $classificationRequestId,
            ),
        );
    }
}

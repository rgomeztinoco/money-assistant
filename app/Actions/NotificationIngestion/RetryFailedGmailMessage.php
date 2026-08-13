<?php

namespace App\Actions\NotificationIngestion;

use App\Jobs\ProcessGmailMessage;
use App\Models\GmailMessageDiscovery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;
use Throwable;

class RetryFailedGmailMessage
{
    public function handle(int $discoveryId): GmailMessageDiscovery
    {
        $discovery = DB::transaction(function () use ($discoveryId): GmailMessageDiscovery {
            $discovery = GmailMessageDiscovery::query()
                ->lockForUpdate()
                ->find($discoveryId);

            if ($discovery === null) {
                throw (new ModelNotFoundException)->setModel(
                    GmailMessageDiscovery::class,
                    [$discoveryId],
                );
            }

            if (
                $discovery->processed_at !== null
                || $discovery->processing_failed_at === null
                || $discovery->failed_job_uuid === null
                || $discovery->gmailConnection->ingestionIsPaused()
            ) {
                throw new InvalidArgumentException(
                    'Only an unprocessed Gmail message with a retained failed job may be retried.',
                );
            }

            $failedJob = app('queue.failer')->find($discovery->failed_job_uuid);

            if (
                ! $failedJob instanceof stdClass
                || ! $this->isMatchingMessageJob($failedJob->payload, $discovery)
            ) {
                throw new InvalidArgumentException(
                    'The retained Gmail message failure is no longer eligible for retry.',
                );
            }

            ProcessGmailMessage::dispatch($discovery->id)->afterCommit();
            app('queue.failer')->forget($failedJob->id);

            $discovery->forceFill([
                'processing_failed_at' => null,
                'last_error_code' => null,
                'failed_job_uuid' => null,
            ])->save();

            return $discovery;
        }, 3);

        return $discovery;
    }

    private function isMatchingMessageJob(
        string $payload,
        GmailMessageDiscovery $discovery,
    ): bool {
        $decodedPayload = json_decode($payload, true);

        if (($decodedPayload['displayName'] ?? null) !== ProcessGmailMessage::class) {
            return false;
        }

        $serializedCommand = $decodedPayload['data']['command'] ?? null;

        if (! is_string($serializedCommand)) {
            return false;
        }

        try {
            $command = unserialize($serializedCommand, [
                'allowed_classes' => [ProcessGmailMessage::class],
            ]);
        } catch (Throwable) {
            return false;
        }

        return $command instanceof ProcessGmailMessage
            && $command->discoveryId === $discovery->id;
    }
}

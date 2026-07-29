<?php

namespace App\Actions\NotificationIngestion;

use App\GmailSynchronizationType;
use App\Jobs\SynchronizeGmail;
use App\Models\GmailConnection;

final class DispatchGmailSynchronizations
{
    public function handle(GmailSynchronizationType $type): void
    {
        GmailConnection::query()
            ->whereNull('reauthorization_required_at')
            ->orderBy('id')
            ->pluck('id')
            ->each(
                fn (int $connectionId) => SynchronizeGmail::dispatch(
                    $connectionId,
                    $type,
                ),
            );
    }
}

<?php

namespace App\Console\Commands;

use App\Actions\Monitoring\ReadApplicationMonitoringSnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:monitor-snapshot')]
#[Description('Print credential-free application monitoring signals')]
class MonitorApplicationSnapshot extends Command
{
    public function handle(ReadApplicationMonitoringSnapshot $readSnapshot): int
    {
        foreach ($readSnapshot->handle() as $check) {
            $this->line(implode("\t", [
                $check['key'],
                $check['severity'],
                $check['state'],
                $check['grace_seconds'],
                $check['message'],
            ]));
        }

        return self::SUCCESS;
    }
}

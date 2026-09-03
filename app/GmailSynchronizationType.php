<?php

namespace App;

enum GmailSynchronizationType: string
{
    public const INCREMENTAL_SCHEDULE = '*/5 * * * *';

    case Incremental = 'incremental';

    case Reconciliation = 'reconciliation';
}

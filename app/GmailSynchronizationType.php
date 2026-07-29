<?php

namespace App;

enum GmailSynchronizationType: string
{
    case Incremental = 'incremental';

    case Reconciliation = 'reconciliation';
}

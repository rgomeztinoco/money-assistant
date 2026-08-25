<?php

namespace App\Contracts\NotificationIngestion;

use App\Integrations\Gmail\GmailMessage;
use App\NotificationIngestion\SupportedSpendingNotification;

interface SpendingNotificationFormatAdapter
{
    /** @return array<string, string> */
    public function fixtureFiles(): array;

    public function match(GmailMessage $message): ?SupportedSpendingNotification;
}

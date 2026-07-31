<?php

namespace App;

enum SpendingNotificationFormatPurpose: string
{
    case Spending = 'spending';
    case Ignore = 'ignore';

    public function isIgnored(): bool
    {
        return $this === self::Ignore;
    }
}

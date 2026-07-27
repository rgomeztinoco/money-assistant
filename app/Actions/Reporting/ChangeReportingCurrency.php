<?php

namespace App\Actions\Reporting;

use App\Currency;
use App\Models\User;

final class ChangeReportingCurrency
{
    public function handle(User $owner, Currency $reportingCurrency): User
    {
        $owner->reporting_currency = $reportingCurrency;
        $owner->save();

        return $owner;
    }
}

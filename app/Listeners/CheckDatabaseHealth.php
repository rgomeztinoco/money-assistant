<?php

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;

class CheckDatabaseHealth
{
    public function handle(DiagnosingHealth $event): void
    {
        DB::select('select 1');
    }
}

<?php

namespace App\Integrations\Gmail;

use RuntimeException;

final class GmailHistoryExpired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Gmail history cursor has expired.');
    }
}

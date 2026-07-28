<?php

namespace App\Integrations\Gmail;

use RuntimeException;

final class GmailReauthorizationRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Gmail must be reauthorized by the owner.');
    }
}

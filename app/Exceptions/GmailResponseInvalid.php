<?php

namespace App\Exceptions;

use App\Integrations\Gmail\GmailRequestFailed;

final class GmailResponseInvalid extends GmailRequestFailed
{
    public static function forOperation(string $operation): self
    {
        return new self("Gmail returned an invalid {$operation} response.");
    }
}

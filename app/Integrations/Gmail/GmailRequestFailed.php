<?php

namespace App\Integrations\Gmail;

use RuntimeException;

final class GmailRequestFailed extends RuntimeException
{
    public static function authorization(): self
    {
        return new self('Gmail authorization could not be completed.');
    }

    public static function refresh(): self
    {
        return new self('Gmail access could not be refreshed.');
    }

    public static function profile(): self
    {
        return new self('The Gmail profile could not be checked.');
    }

    public static function messageIdentity(): self
    {
        return new self('Gmail message identity metadata could not be read.');
    }

    public static function history(): self
    {
        return new self('Gmail history could not be synchronized.');
    }

    public static function messages(): self
    {
        return new self('Gmail messages could not be discovered.');
    }
}

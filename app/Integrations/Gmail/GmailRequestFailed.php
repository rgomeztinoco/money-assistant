<?php

namespace App\Integrations\Gmail;

use RuntimeException;

class GmailRequestFailed extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }

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

    public static function messageSummary(): self
    {
        return new self('Gmail message summary metadata could not be read.');
    }

    public static function message(): self
    {
        return new self('The Gmail message could not be read.');
    }

    public static function history(): self
    {
        return new self('Gmail history could not be synchronized.');
    }

    public static function messages(): self
    {
        return new self('Gmail messages could not be discovered.');
    }

    public function withHttpStatus(int $httpStatus): self
    {
        return new self($this->getMessage(), $httpStatus);
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }
}

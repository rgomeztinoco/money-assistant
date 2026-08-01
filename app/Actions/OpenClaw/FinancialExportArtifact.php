<?php

namespace App\Actions\OpenClaw;

final class FinancialExportArtifact
{
    /** @var resource */
    private mixed $stream;

    /** @param resource $stream */
    public function __construct(
        mixed $stream,
        public readonly string $digest,
        public readonly int $transactionCount,
    ) {
        $this->stream = $stream;
    }

    public function output(): void
    {
        rewind($this->stream);
        fpassthru($this->stream);
    }

    public function __destruct()
    {
        fclose($this->stream);
    }
}

<?php

namespace App\Integrations\Gmail;

use Carbon\CarbonImmutable;

final readonly class GmailMessage
{
    /**
     * @param  array{
     *     spf: array{result: string|null, domain: string|null},
     *     dkim: array{result: string|null, domain: string|null},
     *     dmarc: array{result: string|null, domain: string|null}
     * }  $authentication
     */
    public function __construct(
        public string $messageId,
        public CarbonImmutable $receivedAt,
        public string $fromAddress,
        public string $subject,
        public array $authentication,
        public ?string $textBody,
        public ?string $htmlBody,
    ) {}
}

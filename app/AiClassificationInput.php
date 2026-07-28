<?php

namespace App;

final readonly class AiClassificationInput
{
    /**
     * @param  list<array{path: string, description: string|null, examples: list<string>}>  $categories
     */
    public function __construct(
        public string $merchantDescription,
        public string $kind,
        public int $amountMinor,
        public string $currency,
        public array $categories,
    ) {}
}

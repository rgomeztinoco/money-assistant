<?php

namespace App;

final readonly class AiClassificationResult
{
    public function __construct(
        public ?string $categoryPath,
        public int $confidence,
        public string $explanation,
    ) {}
}

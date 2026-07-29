<?php

namespace App;

final readonly class AiCategoryProposalResult
{
    /** @param list<string> $examples */
    public function __construct(
        public string $name,
        public ?string $parentCategoryPath,
        public ?string $description,
        public array $examples,
    ) {}
}

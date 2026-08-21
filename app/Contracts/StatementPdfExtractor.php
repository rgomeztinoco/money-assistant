<?php

namespace App\Contracts;

interface StatementPdfExtractor
{
    public function extract(string $path): string;
}

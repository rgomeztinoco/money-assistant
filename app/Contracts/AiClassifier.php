<?php

namespace App\Contracts;

use App\AiClassificationInput;
use App\AiClassificationResult;

interface AiClassifier
{
    public function version(): string;

    public function classify(AiClassificationInput $input): AiClassificationResult;
}

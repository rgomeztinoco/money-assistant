<?php

namespace App;

enum AiClassificationOutcome: string
{
    case Medium = 'medium';
    case LowConfidence = 'low_confidence';
    case InvalidCategory = 'invalid_category';
    case Timeout = 'timeout';
    case Unavailable = 'unavailable';
    case Superseded = 'superseded';
}

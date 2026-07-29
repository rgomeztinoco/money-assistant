<?php

namespace App;

enum AiClassificationOutcome: string
{
    case High = 'high';
    case Medium = 'medium';
    case MissingCategory = 'missing_category';
    case LowConfidence = 'low_confidence';
    case InvalidCategory = 'invalid_category';
    case Timeout = 'timeout';
    case Unavailable = 'unavailable';
    case Superseded = 'superseded';
}

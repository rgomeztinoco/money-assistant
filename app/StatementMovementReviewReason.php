<?php

namespace App;

enum StatementMovementReviewReason: string
{
    case MultipleMatches = 'multiple_matches';
    case ConflictingData = 'conflicting_data';
    case LowConfidence = 'low_confidence';
}

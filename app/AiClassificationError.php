<?php

namespace App;

enum AiClassificationError: string
{
    case AuthoritativeAssignment = 'authoritative_assignment';
    case ClassifierTimeout = 'classifier_timeout';
    case ClassifierUnavailable = 'classifier_unavailable';
}

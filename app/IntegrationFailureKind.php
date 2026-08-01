<?php

namespace App;

enum IntegrationFailureKind: string
{
    case Transient = 'transient';
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case Schema = 'schema';
    case Confirmation = 'confirmation';
    case Concurrency = 'concurrency';
    case Validation = 'validation';
    case Deterministic = 'deterministic';

    public function isTransient(): bool
    {
        return $this === self::Transient;
    }
}

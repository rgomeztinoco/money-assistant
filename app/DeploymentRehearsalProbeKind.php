<?php

namespace App;

enum DeploymentRehearsalProbeKind: string
{
    case Queued = 'queued';
    case Scheduled = 'scheduled';
}

<?php

namespace App;

enum RuntimeService: string
{
    case Scheduler = 'scheduler';
    case Worker = 'worker';
}

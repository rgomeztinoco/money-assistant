<?php

namespace App;

enum SuspectedDuplicateOperation: string
{
    case Resolve = 'resolve';
    case Reopen = 'reopen';
}

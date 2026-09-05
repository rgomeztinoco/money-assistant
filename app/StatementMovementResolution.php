<?php

namespace App;

enum StatementMovementResolution: string
{
    case Linked = 'linked';
    case Created = 'created';
    case Excluded = 'excluded';
}

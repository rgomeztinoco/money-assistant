<?php

namespace App;

enum CategoryAssignmentProvenance: string
{
    case Owner = 'owner';
    case LinkedRefund = 'linked_refund';
    case LearnedRule = 'learned_rule';
    case Ai = 'ai';
}

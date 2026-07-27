<?php

namespace App;

enum LearnedRuleMatchMode: string
{
    case Exact = 'exact';
    case StartsWith = 'starts_with';
    case Contains = 'contains';
}

<?php

namespace App;

enum LearnedRuleSuggestionStatus: string
{
    case Collecting = 'collecting';
    case Pending = 'pending';
    case Dismissed = 'dismissed';
    case Accepted = 'accepted';
}

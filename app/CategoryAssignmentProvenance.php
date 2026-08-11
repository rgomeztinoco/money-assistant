<?php

namespace App;

enum CategoryAssignmentProvenance: string
{
    case Owner = 'owner';
    case MerchantRule = 'merchant_rule';
}

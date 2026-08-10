<?php

namespace App;

enum IntegrationService: string
{
    case Gmail = 'gmail';
    case Bcrp = 'bcrp';
    case OpenClaw = 'openclaw';
}

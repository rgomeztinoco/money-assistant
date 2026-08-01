<?php

namespace App;

enum IntegrationService: string
{
    case Gmail = 'gmail';
    case Ai = 'ai';
    case Bcrp = 'bcrp';
    case OpenClaw = 'openclaw';
}

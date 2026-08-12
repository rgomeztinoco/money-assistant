<?php

namespace App;

enum IntegrationService: string
{
    case Gmail = 'gmail';
    case OpenClaw = 'openclaw';
}

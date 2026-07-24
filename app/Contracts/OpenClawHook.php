<?php

namespace App\Contracts;

use DateTimeInterface;

interface OpenClawHook
{
    public function dispatch(string $eventId, string $eventType, DateTimeInterface $occurredAt): void;
}

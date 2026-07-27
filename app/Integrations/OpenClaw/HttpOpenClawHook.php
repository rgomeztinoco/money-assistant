<?php

namespace App\Integrations\OpenClaw;

use App\Contracts\OpenClawHook;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Uri;
use RuntimeException;
use Throwable;

final class HttpOpenClawHook implements OpenClawHook
{
    public function __construct(
        private readonly string $url,
        private readonly string $token,
    ) {}

    public function dispatch(string $eventId, string $eventType, DateTimeInterface $occurredAt): void
    {
        $this->validateUrl();

        Http::acceptJson()
            ->asJson()
            ->withToken($this->token)
            ->withHeader('Idempotency-Key', $eventId)
            ->connectTimeout(3)
            ->timeout(10)
            ->retry(
                [200, 1000],
                when: fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
            )
            ->post($this->url, [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'occurred_at' => Carbon::instance($occurredAt)->utc()->format('Y-m-d\TH:i:s\Z'),
            ])
            ->throw();
    }

    private function validateUrl(): void
    {
        $uri = Uri::of($this->url);

        if ($uri->scheme() !== 'http'
            || $uri->host() !== '127.0.0.1'
            || $uri->port() !== 18789
            || $uri->path() !== 'hooks/money-assistant'
            || $uri->query()->value() !== ''
            || $uri->fragment() !== null
            || $uri->user() !== null
            || $this->token === '') {
            throw new RuntimeException('The OpenClaw hook URL must be the dedicated loopback hook.');
        }
    }
}

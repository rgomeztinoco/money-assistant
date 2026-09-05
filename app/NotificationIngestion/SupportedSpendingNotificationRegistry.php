<?php

namespace App\NotificationIngestion;

use App\Contracts\NotificationIngestion\SpendingNotificationFormatAdapter;
use App\Integrations\Gmail\GmailMessage;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonException;
use LogicException;

final class SupportedSpendingNotificationRegistry
{
    /** @param list<SpendingNotificationFormatAdapter> $adapters */
    public function __construct(private array $adapters)
    {
        $identifiers = [];

        foreach ($adapters as $adapter) {
            foreach ($adapter->fixtureFiles() as $identifier => $fixtureFile) {
                if (isset($identifiers[$identifier])) {
                    throw new LogicException("Duplicate Gmail format identifier [{$identifier}].");
                }

                if (! is_file($this->fixturePath($fixtureFile))) {
                    throw new LogicException("Gmail format [{$identifier}] has no representative fixture.");
                }

                $identifiers[$identifier] = true;
            }
        }
    }

    public function match(GmailMessage $message): ?SupportedSpendingNotification
    {
        $matches = [];

        foreach ($this->adapters as $adapter) {
            $match = $adapter->match($message);

            if ($match !== null) {
                $matches[] = $match;
            }
        }

        if (count($matches) > 1) {
            throw new InvalidArgumentException('A Gmail message matched more than one app-owned format.');
        }

        return $matches[0] ?? null;
    }

    /** @return array<string, bool> */
    public function verifyFixtures(): array
    {
        $results = [];

        foreach ($this->adapters as $adapter) {
            foreach ($adapter->fixtureFiles() as $identifier => $fixtureFile) {
                $fixture = $this->readFixture($fixtureFile);
                try {
                    $match = $this->match($this->messageFromFixture($fixture));
                } catch (InvalidArgumentException $exception) {
                    throw new LogicException(
                        "Gmail fixture [{$identifier}] does not satisfy its format.",
                        previous: $exception,
                    );
                }
                $results[$identifier] = $match?->formatIdentifier === $identifier
                    && $match->fixtureExpectation() === ($fixture['expected'] ?? null);
            }
        }

        return $results;
    }

    private function fixturePath(string $fixtureFile): string
    {
        return resource_path('notification-formats/'.$fixtureFile);
    }

    /** @return array<string, mixed> */
    private function readFixture(string $fixtureFile): array
    {
        try {
            $fixture = json_decode(
                (string) file_get_contents($this->fixturePath($fixtureFile)),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new LogicException("Gmail fixture [{$fixtureFile}] is invalid JSON.", previous: $exception);
        }

        if (! is_array($fixture)) {
            throw new LogicException("Gmail fixture [{$fixtureFile}] must contain an object.");
        }

        return $fixture;
    }

    /** @param array<string, mixed> $fixture */
    private function messageFromFixture(array $fixture): GmailMessage
    {
        $message = $fixture['message'] ?? null;

        if (! is_array($message)
            || ! is_string($message['message_id'] ?? null)
            || ! is_string($message['received_at'] ?? null)
            || ! is_string($message['from_address'] ?? null)
            || ! is_string($message['subject'] ?? null)
            || ! is_array($message['authentication'] ?? null)) {
            throw new LogicException('A Gmail fixture has an invalid message envelope.');
        }

        return new GmailMessage(
            messageId: $message['message_id'],
            receivedAt: CarbonImmutable::parse($message['received_at']),
            fromAddress: $message['from_address'],
            subject: $message['subject'],
            authentication: [
                'spf' => $this->authenticationResult($message['authentication']['spf'] ?? null),
                'dkim' => $this->authenticationResult($message['authentication']['dkim'] ?? null),
                'dmarc' => $this->authenticationResult($message['authentication']['dmarc'] ?? null),
            ],
            textBody: is_string($message['text_body'] ?? null) ? $message['text_body'] : null,
            htmlBody: is_string($message['html_body'] ?? null) ? $message['html_body'] : null,
        );
    }

    /** @return array{result: string|null, domain: string|null} */
    private function authenticationResult(mixed $value): array
    {
        if (! is_array($value)
            || (! is_string($value['result'] ?? null) && ($value['result'] ?? null) !== null)
            || (! is_string($value['domain'] ?? null) && ($value['domain'] ?? null) !== null)) {
            throw new LogicException('A Gmail fixture has invalid authentication evidence.');
        }

        return [
            'result' => $value['result'] ?? null,
            'domain' => $value['domain'] ?? null,
        ];
    }
}

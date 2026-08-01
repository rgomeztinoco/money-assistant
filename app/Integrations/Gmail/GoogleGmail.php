<?php

namespace App\Integrations\Gmail;

use App\Contracts\Gmail;
use App\Exceptions\GmailResponseInvalid;
use Carbon\CarbonImmutable;
use Google\Client;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail as GmailService;
use Google\Service\Gmail\MessagePart;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Throwable;

final class GoogleGmail implements Gmail
{
    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $redirectUri,
        private ?ClientInterface $httpClient = null,
    ) {}

    public function authorizationUrl(string $state, ?string $loginHint = null): string
    {
        $client = $this->client();
        $client->setAccessType('offline');
        $client->setIncludeGrantedScopes(false);
        $client->setPrompt('consent');
        $client->setState($state);

        if ($loginHint !== null) {
            $client->setLoginHint($loginHint);
        }

        return $client->createAuthUrl();
    }

    public function authorize(string $code): GmailAuthorization
    {
        $client = $this->client();

        try {
            $credentials = $client->fetchAccessTokenWithAuthCode($code);
        } catch (Throwable) {
            throw GmailRequestFailed::authorization();
        }

        $authorization = $this->authorizationCredentials($credentials);

        $profile = $this->profileWithClient($client, GmailRequestFailed::authorization());

        return new GmailAuthorization(
            accessToken: $authorization['access_token'],
            refreshToken: $authorization['refresh_token'],
            accessTokenExpiresAt: CarbonImmutable::now()->addSeconds($authorization['expires_in']),
            grantedScopes: $authorization['granted_scopes'],
            accountIdentity: $profile->accountIdentity,
            historyId: $profile->historyId,
        );
    }

    public function refresh(string $refreshToken): GmailAccess
    {
        $client = $this->client();

        try {
            $credentials = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        } catch (ClientException $exception) {
            $payload = json_decode((string) $exception->getResponse()->getBody(), true);

            if (is_array($payload) && ($payload['error'] ?? null) === 'invalid_grant') {
                throw new GmailReauthorizationRequired;
            }

            throw GmailRequestFailed::refresh()->withHttpStatus(
                $exception->getResponse()->getStatusCode(),
            );
        } catch (Throwable) {
            throw GmailRequestFailed::refresh();
        }

        if (($credentials['error'] ?? null) === 'invalid_grant') {
            throw new GmailReauthorizationRequired;
        }

        $grantedScopes = $this->grantedScopes($credentials);

        if (! is_string($credentials['access_token'] ?? null)
            || $credentials['access_token'] === ''
            || ! is_int($credentials['expires_in'] ?? null)
            || $credentials['expires_in'] <= 0
            || $grantedScopes !== [Gmail::READ_ONLY_SCOPE]) {
            throw GmailResponseInvalid::forOperation('refresh');
        }

        return new GmailAccess(
            accessToken: $credentials['access_token'],
            accessTokenExpiresAt: CarbonImmutable::now()->addSeconds($credentials['expires_in']),
        );
    }

    public function profile(string $accessToken): GmailProfile
    {
        $client = $this->client();
        $this->setAccessToken($client, $accessToken);

        return $this->profileWithClient($client, GmailRequestFailed::profile());
    }

    public function history(
        string $accessToken,
        string $startHistoryId,
        ?string $pageToken = null,
    ): GmailHistoryPage {
        $client = $this->client();
        $this->setAccessToken($client, $accessToken);

        try {
            $response = (new GmailService($client))->users_history->listUsersHistory('me', array_filter([
                'historyTypes' => 'messageAdded',
                'maxResults' => 500,
                'pageToken' => $pageToken,
                'startHistoryId' => $startHistoryId,
            ], static fn (mixed $value): bool => $value !== null));
        } catch (GoogleServiceException $exception) {
            if ($exception->getCode() === 404) {
                throw new GmailHistoryExpired;
            }

            throw GmailRequestFailed::history()->withHttpStatus($exception->getCode());
        } catch (Throwable) {
            throw GmailRequestFailed::history();
        }

        $messageIds = [];

        foreach ($response->getHistory() ?? [] as $history) {
            foreach ($history->getMessagesAdded() ?? [] as $messageAdded) {
                $messageId = $messageAdded->getMessage()?->getId();

                if (is_string($messageId) && $messageId !== '') {
                    $messageIds[$messageId] = true;
                }
            }
        }

        return new GmailHistoryPage(
            messageIds: array_keys($messageIds),
            historyId: (string) $response->getHistoryId(),
            nextPageToken: filled($response->getNextPageToken())
                ? (string) $response->getNextPageToken()
                : null,
        );
    }

    public function messagesAfter(
        string $accessToken,
        int $afterEpochSeconds,
        ?string $pageToken = null,
    ): GmailMessagePage {
        $client = $this->client();
        $this->setAccessToken($client, $accessToken);

        try {
            $response = (new GmailService($client))->users_messages->listUsersMessages('me', array_filter([
                'includeSpamTrash' => true,
                'maxResults' => 500,
                'pageToken' => $pageToken,
                'q' => "in:anywhere after:{$afterEpochSeconds}",
            ], static fn (mixed $value): bool => $value !== null));
        } catch (Throwable $exception) {
            throw $this->requestFailure($exception, GmailRequestFailed::messages());
        }

        $messageIds = [];

        foreach ($response->getMessages() ?? [] as $message) {
            $messageId = $message->getId();

            if (is_string($messageId) && $messageId !== '') {
                $messageIds[$messageId] = true;
            }
        }

        return new GmailMessagePage(
            messageIds: array_keys($messageIds),
            nextPageToken: filled($response->getNextPageToken())
                ? (string) $response->getNextPageToken()
                : null,
        );
    }

    public function messageIdentity(
        string $accessToken,
        string $messageId,
    ): GmailMessageIdentity {
        $client = $this->client();
        $this->setAccessToken($client, $accessToken);

        try {
            $message = (new GmailService($client))->users_messages->get('me', $messageId, [
                'fields' => 'id,internalDate',
                'format' => 'full',
            ]);
        } catch (Throwable $exception) {
            throw $this->requestFailure($exception, GmailRequestFailed::messageIdentity());
        }
        $returnedMessageId = $message->getId();
        $receivedAt = $message->getInternalDate();

        if (! is_string($returnedMessageId)
            || $returnedMessageId !== $messageId
            || ! is_string($receivedAt)
            || ! ctype_digit($receivedAt)) {
            throw GmailResponseInvalid::forOperation('message identity');
        }

        return new GmailMessageIdentity(
            messageId: $returnedMessageId,
            receivedAt: CarbonImmutable::createFromTimestampMsUTC($receivedAt),
        );
    }

    public function message(
        string $accessToken,
        string $messageId,
    ): GmailMessage {
        $client = $this->client();
        $this->setAccessToken($client, $accessToken);
        $service = new GmailService($client);

        try {
            $message = $service->users_messages->get('me', $messageId, [
                'format' => 'full',
            ]);
        } catch (Throwable $exception) {
            throw $this->requestFailure($exception, GmailRequestFailed::message());
        }

        $returnedMessageId = $message->getId();
        $receivedAt = $message->getInternalDate();
        $payload = $message->getPayload();

        if (! is_string($returnedMessageId)
            || $returnedMessageId !== $messageId
            || ! is_string($receivedAt)
            || ! ctype_digit($receivedAt)
            || ! $payload instanceof MessagePart) {
            throw GmailResponseInvalid::forOperation('message');
        }

        $headers = $this->messageHeaders($payload);

        $fromAddress = $this->emailAddress($headers['from'][0] ?? null);
        $subject = $headers['subject'][0] ?? null;

        if ($fromAddress === null || ! is_string($subject)) {
            throw GmailResponseInvalid::forOperation('message');
        }

        $mimeParts = $this->mimeParts($service, $messageId, $payload);

        return new GmailMessage(
            messageId: $returnedMessageId,
            receivedAt: CarbonImmutable::createFromTimestampMsUTC($receivedAt),
            fromAddress: $fromAddress,
            subject: $subject,
            authentication: $this->authenticationResults(
                $headers['authentication-results'] ?? [],
            ),
            textBody: $mimeParts['text/plain'],
            htmlBody: $mimeParts['text/html'],
        );
    }

    public function messageSummary(
        string $accessToken,
        string $messageId,
    ): GmailMessageSummary {
        $client = $this->client();
        $this->setAccessToken($client, $accessToken);

        try {
            $message = (new GmailService($client))->users_messages->get(
                'me',
                $messageId,
                [
                    'fields' => 'id,internalDate,payload(headers)',
                    'format' => 'metadata',
                    'metadataHeaders' => ['From', 'Subject'],
                ],
            );
        } catch (Throwable $exception) {
            throw $this->requestFailure($exception, GmailRequestFailed::messageSummary());
        }

        $returnedMessageId = $message->getId();
        $receivedAt = $message->getInternalDate();
        $payload = $message->getPayload();

        if (! is_string($returnedMessageId)
            || $returnedMessageId !== $messageId
            || ! is_string($receivedAt)
            || ! ctype_digit($receivedAt)
            || ! $payload instanceof MessagePart) {
            throw GmailResponseInvalid::forOperation('message summary');
        }

        $headers = $this->messageHeaders($payload);
        $fromAddress = $this->emailAddress($headers['from'][0] ?? null);
        $subject = $headers['subject'][0] ?? null;

        if ($fromAddress === null || ! is_string($subject)) {
            throw GmailResponseInvalid::forOperation('message summary');
        }

        return new GmailMessageSummary(
            messageId: $returnedMessageId,
            receivedAt: CarbonImmutable::createFromTimestampMsUTC($receivedAt),
            fromAddress: $fromAddress,
            subject: $subject,
        );
    }

    private function client(): Client
    {
        $client = new Client;
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);
        $client->setScopes([Gmail::READ_ONLY_SCOPE]);
        $client->setLogger(new NullLogger);

        $client->setHttpClient($this->httpClient ?? $this->defaultHttpClient());

        return $client;
    }

    private function setAccessToken(Client $client, string $accessToken): void
    {
        $client->setAccessToken([
            'access_token' => $accessToken,
            'expires_in' => 3600,
            'created' => time(),
            'token_type' => 'Bearer',
        ]);
    }

    private function defaultHttpClient(): ClientInterface
    {
        $handlerStack = HandlerStack::create();
        $handlerStack->push(Middleware::retry(
            decider: static function (
                int $retries,
                RequestInterface $request,
                ?ResponseInterface $response,
                ?Throwable $exception,
            ): bool {
                if ($retries >= 2) {
                    return false;
                }

                return $exception instanceof ConnectException
                    || ($response !== null && ($response->getStatusCode() === 429 || $response->getStatusCode() >= 500));
            },
            delay: static fn (int $retries): int => $retries === 1 ? 200 : 1000,
        ));

        return new GuzzleClient([
            'handler' => $handlerStack,
            'connect_timeout' => 3,
            'timeout' => 10,
        ]);
    }

    private function emailAddress(mixed $fromHeader): ?string
    {
        if (! is_string($fromHeader)) {
            return null;
        }

        $candidate = $fromHeader;

        if (preg_match('/<([^<>]+)>/', $fromHeader, $matches) === 1) {
            $candidate = $matches[1];
        }

        $candidate = mb_strtolower(trim($candidate));

        return filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false
            ? $candidate
            : null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function messageHeaders(MessagePart $payload): array
    {
        $headers = [];

        foreach ($payload->getHeaders() as $header) {
            $name = $header->getName();
            $value = $header->getValue();
            $headers[mb_strtolower($name)][] = $value;
        }

        return $headers;
    }

    /**
     * @param  list<string>  $authenticationHeaders
     * @return array{
     *     spf: array{result: string|null, domain: string|null},
     *     dkim: array{result: string|null, domain: string|null},
     *     dmarc: array{result: string|null, domain: string|null}
     * }
     */
    private function authenticationResults(array $authenticationHeaders): array
    {
        $gmailAuthenticationHeader = null;

        foreach ($authenticationHeaders as $authenticationHeader) {
            if (preg_match('/^\s*mx\.google\.com\s*;/i', $authenticationHeader) === 1) {
                $gmailAuthenticationHeader = $authenticationHeader;

                break;
            }
        }

        $authenticationHeaders = $gmailAuthenticationHeader === null
            ? []
            : [$gmailAuthenticationHeader];

        return [
            'spf' => $this->authenticationResult(
                $authenticationHeaders,
                'spf',
                'smtp.mailfrom',
            ),
            'dkim' => $this->authenticationResult(
                $authenticationHeaders,
                'dkim',
                'header.d',
            ),
            'dmarc' => $this->authenticationResult(
                $authenticationHeaders,
                'dmarc',
                'header.from',
            ),
        ];
    }

    /**
     * @param  list<string>  $authenticationHeaders
     * @return array{result: string|null, domain: string|null}
     */
    private function authenticationResult(
        array $authenticationHeaders,
        string $mechanism,
        string $domainAttribute,
    ): array {
        $result = null;
        $domain = null;
        $escapedMechanism = preg_quote($mechanism, '/');
        $escapedDomainAttribute = preg_quote($domainAttribute, '/');

        foreach ($authenticationHeaders as $authenticationHeader) {
            if (preg_match(
                "/(?:^|[\\s;]){$escapedMechanism}=([a-z]+)\\b([^;]*)/i",
                $authenticationHeader,
                $resultMatch,
            ) !== 1) {
                continue;
            }

            $result = mb_strtolower($resultMatch[1]);

            if (preg_match(
                "/\\b{$escapedDomainAttribute}=<?([^\\s;>]+)>?/i",
                $resultMatch[2],
                $domainMatch,
            ) === 1) {
                $authenticatedIdentity = mb_strtolower(
                    trim($domainMatch[1], "\"'"),
                );
                $domain = Str::contains($authenticatedIdentity, '@')
                    ? Str::afterLast($authenticatedIdentity, '@')
                    : $authenticatedIdentity;
            }
        }

        return [
            'result' => $result,
            'domain' => $domain,
        ];
    }

    /**
     * @return array{'text/plain': string|null, 'text/html': string|null}
     */
    private function mimeParts(
        GmailService $service,
        string $messageId,
        MessagePart $part,
    ): array {
        $mimeParts = [
            'text/plain' => null,
            'text/html' => null,
        ];
        $mimeType = mb_strtolower((string) $part->getMimeType());

        if (array_key_exists($mimeType, $mimeParts)) {
            $mimeParts[$mimeType] = $this->messagePartBody(
                $service,
                $messageId,
                $part,
            );
        }

        foreach ($part->getParts() as $childPart) {
            $childMimeParts = $this->mimeParts($service, $messageId, $childPart);

            foreach ($mimeParts as $type => $content) {
                $mimeParts[$type] ??= $childMimeParts[$type];
            }
        }

        return $mimeParts;
    }

    private function messagePartBody(
        GmailService $service,
        string $messageId,
        MessagePart $part,
    ): ?string {
        $body = $part->getBody();
        $encoded = $body->getData();
        $attachmentId = $body->getAttachmentId();

        if ($encoded === '' && $attachmentId !== '') {
            try {
                $encoded = $service->users_messages_attachments
                    ->get('me', $messageId, $attachmentId)
                    ->getData();
            } catch (Throwable $exception) {
                throw $this->requestFailure($exception, GmailRequestFailed::message());
            }
        }

        if ($encoded === '') {
            return null;
        }

        $encoded = strtr($encoded, '-_', '+/');
        $paddingLength = (4 - mb_strlen($encoded) % 4) % 4;
        $decoded = base64_decode($encoded.str_repeat('=', $paddingLength), true);

        if (! is_string($decoded)) {
            throw GmailResponseInvalid::forOperation('message body');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{access_token: string, refresh_token: string, expires_in: int, granted_scopes: list<string>}
     */
    private function authorizationCredentials(array $credentials): array
    {
        $grantedScopes = $this->grantedScopes($credentials);

        if (! is_string($credentials['access_token'] ?? null)
            || $credentials['access_token'] === ''
            || ! is_string($credentials['refresh_token'] ?? null)
            || $credentials['refresh_token'] === ''
            || ! is_int($credentials['expires_in'] ?? null)
            || $credentials['expires_in'] <= 0
            || $grantedScopes !== [Gmail::READ_ONLY_SCOPE]) {
            throw GmailResponseInvalid::forOperation('authorization');
        }

        return [
            'access_token' => $credentials['access_token'],
            'refresh_token' => $credentials['refresh_token'],
            'expires_in' => $credentials['expires_in'],
            'granted_scopes' => $grantedScopes,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return list<string>
     */
    private function grantedScopes(array $credentials): array
    {
        $scope = $credentials['scope'] ?? Gmail::READ_ONLY_SCOPE;

        return is_string($scope)
            ? array_values(array_filter(explode(' ', $scope)))
            : [];
    }

    private function profileWithClient(Client $client, GmailRequestFailed $failure): GmailProfile
    {
        try {
            $profile = (new GmailService($client))->users->getProfile('me');
        } catch (Throwable $exception) {
            throw $this->requestFailure($exception, $failure);
        }

        $accountIdentity = $profile->getEmailAddress();
        $historyId = $profile->getHistoryId();

        if (! is_string($accountIdentity)
            || $accountIdentity === ''
            || ! is_string($historyId)
            || $historyId === '') {
            throw GmailResponseInvalid::forOperation('profile');
        }

        return new GmailProfile($accountIdentity, $historyId);
    }

    private function requestFailure(
        Throwable $exception,
        GmailRequestFailed $fallback,
    ): GmailRequestFailed {
        return $exception instanceof GoogleServiceException
            ? $fallback->withHttpStatus($exception->getCode())
            : $fallback;
    }
}

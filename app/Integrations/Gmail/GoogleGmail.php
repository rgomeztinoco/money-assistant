<?php

namespace App\Integrations\Gmail;

use App\Contracts\Gmail;
use Carbon\CarbonImmutable;
use Google\Client;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail as GmailService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
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

            throw GmailRequestFailed::refresh();
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
            throw GmailRequestFailed::refresh();
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

            throw GmailRequestFailed::history();
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
        } catch (Throwable) {
            throw GmailRequestFailed::messages();
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
        } catch (Throwable) {
            throw GmailRequestFailed::messageIdentity();
        }
        $returnedMessageId = $message->getId();
        $receivedAt = $message->getInternalDate();

        if (! is_string($returnedMessageId)
            || $returnedMessageId !== $messageId
            || ! is_string($receivedAt)
            || ! ctype_digit($receivedAt)) {
            throw GmailRequestFailed::messageIdentity();
        }

        return new GmailMessageIdentity(
            messageId: $returnedMessageId,
            receivedAt: CarbonImmutable::createFromTimestampMsUTC($receivedAt),
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
            throw GmailRequestFailed::authorization();
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
        } catch (Throwable) {
            throw $failure;
        }

        $accountIdentity = $profile->getEmailAddress();
        $historyId = $profile->getHistoryId();

        if (! is_string($accountIdentity)
            || $accountIdentity === ''
            || ! is_string($historyId)
            || $historyId === '') {
            throw $failure;
        }

        return new GmailProfile($accountIdentity, $historyId);
    }
}

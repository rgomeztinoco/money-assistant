<?php

namespace App\Actions\Integrations;

use App\Exceptions\GmailResponseInvalid;
use App\Exceptions\IdempotencyKeyConflict;
use App\Exceptions\StaleDailyExchangeRateRevision;
use App\IntegrationFailureKind;
use App\Integrations\Gmail\GmailReauthorizationRequired;
use App\Integrations\Gmail\GmailRequestFailed;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

final class ClassifyIntegrationFailure
{
    public function handle(Throwable $exception): IntegrationFailureKind
    {
        $chain = $this->exceptionChain($exception);

        foreach ($chain as $failure) {
            if ($failure instanceof RequestException) {
                return $this->httpStatusKind($failure->response->status());
            }

            if ($failure instanceof GmailRequestFailed && $failure->httpStatus() !== null) {
                return $this->httpStatusKind($failure->httpStatus());
            }
        }

        foreach ($chain as $failure) {
            $kind = match (true) {
                $failure instanceof GmailReauthorizationRequired,
                $failure instanceof AuthenticationException => IntegrationFailureKind::Authentication,
                $failure instanceof AuthorizationException => IntegrationFailureKind::Authorization,
                $failure instanceof IdempotencyKeyConflict,
                $failure instanceof StaleDailyExchangeRateRevision => IntegrationFailureKind::Concurrency,
                $failure instanceof GmailResponseInvalid,
                $failure instanceof UnexpectedValueException => IntegrationFailureKind::Schema,
                $failure instanceof ValidationException,
                $failure instanceof InvalidArgumentException => IntegrationFailureKind::Validation,
                $failure instanceof ConnectionException,
                $failure instanceof GmailRequestFailed => IntegrationFailureKind::Transient,
                default => null,
            };

            if ($kind !== null) {
                return $kind;
            }
        }

        return IntegrationFailureKind::Deterministic;
    }

    private function httpStatusKind(int $status): IntegrationFailureKind
    {
        return match ($status) {
            401 => IntegrationFailureKind::Authentication,
            403 => IntegrationFailureKind::Authorization,
            409 => IntegrationFailureKind::Concurrency,
            422 => IntegrationFailureKind::Validation,
            408, 425, 429 => IntegrationFailureKind::Transient,
            default => $status >= 500
                ? IntegrationFailureKind::Transient
                : IntegrationFailureKind::Schema,
        };
    }

    /** @return list<Throwable> */
    private function exceptionChain(Throwable $exception): array
    {
        $chain = [];

        do {
            $chain[] = $exception;
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return $chain;
    }
}

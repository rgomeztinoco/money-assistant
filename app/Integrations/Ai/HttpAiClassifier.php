<?php

namespace App\Integrations\Ai;

use App\AiClassificationInput;
use App\AiClassificationResult;
use App\Contracts\AiClassifier;
use App\Exceptions\AiClassifierTimedOut;
use App\Exceptions\AiClassifierUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class HttpAiClassifier implements AiClassifier
{
    public function __construct(
        private string $url,
        private string $token,
        private string $classifierVersion,
    ) {}

    public function version(): string
    {
        return $this->classifierVersion;
    }

    public function classify(AiClassificationInput $input): AiClassificationResult
    {
        try {
            $response = Http::acceptJson()
                ->withToken($this->token)
                ->connectTimeout(3)
                ->timeout(10)
                ->retry(
                    [100, 500],
                    when: fn (Throwable $exception): bool => $exception instanceof ConnectionException
                        || ($exception instanceof RequestException
                            && $exception->response->serverError()),
                )
                ->post($this->url, [
                    'merchant_description' => $input->merchantDescription,
                    'kind' => $input->kind,
                    'amount_minor' => $input->amountMinor,
                    'currency' => $input->currency,
                    'categories' => $input->categories,
                ])
                ->throw();
        } catch (ConnectionException $exception) {
            if (Str::contains(Str::lower($exception->getMessage()), ['timed out', 'timeout'])) {
                throw new AiClassifierTimedOut(
                    'The AI classifier request timed out.',
                    previous: $exception,
                );
            }

            throw new AiClassifierUnavailable(
                'The AI classifier could not be reached.',
                previous: $exception,
            );
        } catch (RequestException $exception) {
            throw new AiClassifierUnavailable(
                'The AI classifier rejected or could not process the request.',
                previous: $exception,
            );
        }

        $categoryPath = $response->json('category_path');
        $confidence = $response->json('confidence');
        $explanation = $response->json('explanation');

        if (! is_int($confidence)
            || $confidence < 0
            || $confidence > 100
            || ! is_string($explanation)
            || Str::squish($explanation) === ''
            || Str::length($explanation) > 500) {
            throw new AiClassifierUnavailable(
                'The AI classifier returned an invalid structured result.',
            );
        }

        $categoryPath = is_string($categoryPath) && Str::squish($categoryPath) !== ''
            ? Str::squish($categoryPath)
            : null;

        return new AiClassificationResult(
            categoryPath: $categoryPath,
            confidence: $confidence,
            explanation: Str::squish($explanation),
        );
    }
}

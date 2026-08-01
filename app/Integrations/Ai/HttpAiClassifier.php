<?php

namespace App\Integrations\Ai;

use App\AiCategoryProposalResult;
use App\AiClassificationInput;
use App\AiClassificationResult;
use App\Contracts\AiClassifier;
use App\Exceptions\AiClassifierResponseInvalid;
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
        $categoryProposal = $response->json('category_proposal');
        $confidence = $response->json('confidence');
        $explanation = $response->json('explanation');

        if (! is_int($confidence)
            || $confidence < 0
            || $confidence > 100
            || ! is_string($explanation)
            || Str::squish($explanation) === ''
            || Str::length($explanation) > 500) {
            throw new AiClassifierResponseInvalid(
                'The AI classifier returned an invalid structured result.',
            );
        }

        $categoryPath = is_string($categoryPath) && Str::squish($categoryPath) !== ''
            ? Str::squish($categoryPath)
            : null;
        $categoryProposal = $this->categoryProposal($categoryProposal);

        if ($categoryPath !== null && $categoryProposal !== null) {
            throw new AiClassifierResponseInvalid(
                'The AI classifier returned an invalid structured result.',
            );
        }

        return new AiClassificationResult(
            categoryPath: $categoryPath,
            confidence: $confidence,
            explanation: Str::squish($explanation),
            categoryProposal: $categoryProposal,
        );
    }

    private function categoryProposal(mixed $value): ?AiCategoryProposalResult
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            $this->throwInvalidResult();
        }

        $name = $value['name'] ?? null;
        $parentCategoryPath = $value['parent_category_path'] ?? null;
        $description = $value['description'] ?? null;
        $examples = $value['examples'] ?? null;

        if (! is_string($name)
            || Str::squish($name) === ''
            || Str::length(Str::squish($name)) > 255
            || ($parentCategoryPath !== null
                && (! is_string($parentCategoryPath)
                    || Str::squish($parentCategoryPath) === ''
                    || Str::length(Str::squish($parentCategoryPath)) > 255))
            || ($description !== null
                && (! is_string($description)
                    || Str::length(Str::squish($description)) > 2000))
            || ! is_array($examples)
            || ! array_is_list($examples)
            || count($examples) > 20
            || collect($examples)->contains(fn (mixed $example): bool => ! is_string($example)
                || Str::squish($example) === ''
                || Str::length(Str::squish($example)) > 100)) {
            $this->throwInvalidResult();
        }

        $normalizedDescription = Str::squish((string) $description);

        return new AiCategoryProposalResult(
            name: Str::squish($name),
            parentCategoryPath: $parentCategoryPath === null
                ? null
                : Str::squish($parentCategoryPath),
            description: $normalizedDescription === '' ? null : $normalizedDescription,
            examples: array_values(collect($examples)
                ->map(fn (string $example): string => Str::squish($example))
                ->unique(fn (string $example): string => Str::lower($example))
                ->all()),
        );
    }

    private function throwInvalidResult(): never
    {
        throw new AiClassifierResponseInvalid(
            'The AI classifier returned an invalid structured result.',
        );
    }
}

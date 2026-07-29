<?php

use App\AiClassificationInput;
use App\Contracts\AiClassifier;
use App\Exceptions\AiClassifierTimedOut;
use App\Exceptions\AiClassifierUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.ai_classifier', [
        'url' => 'https://classifier.example.test/v1/classify',
        'token' => 'classifier-secret',
        'version' => 'classifier-2026-07',
    ]);
    Http::preventStrayRequests();
});

test('the HTTP classifier sends the minimal contract and validates its structured result', function () {
    Http::fake([
        'https://classifier.example.test/v1/classify' => Http::response([
            'category_path' => 'Food > Groceries',
            'confidence' => 87,
            'explanation' => 'Merchant and guidance indicate groceries.',
        ]),
    ]);

    $result = app(AiClassifier::class)->classify(aiClassifierInput());

    expect(app(AiClassifier::class)->version())->toBe('classifier-2026-07')
        ->and($result->categoryPath)->toBe('Food > Groceries')
        ->and($result->confidence)->toBe(87)
        ->and($result->explanation)->toBe('Merchant and guidance indicate groceries.');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://classifier.example.test/v1/classify'
            && $request->hasHeader('Authorization', 'Bearer classifier-secret')
            && $request->data() === [
                'merchant_description' => 'café central',
                'kind' => 'purchase',
                'amount_minor' => 12_450,
                'currency' => 'PEN',
                'categories' => [
                    [
                        'path' => 'Food > Groceries',
                        'description' => 'Ingredients and household staples.',
                        'examples' => ['Supermarkets'],
                    ],
                ],
            ];
    });
});

test('transport timeout and service unavailability remain distinct boundary failures', function (
    mixed $response,
    string $exceptionClass,
) {
    Http::fake([
        'https://classifier.example.test/v1/classify' => $response,
    ]);

    expect(fn () => app(AiClassifier::class)->classify(aiClassifierInput()))
        ->toThrow($exceptionClass);

    Http::assertSentCount(3);
})->with([
    'timeout' => [
        fn () => Http::failedConnection('Operation timed out after 10 seconds'),
        AiClassifierTimedOut::class,
    ],
    'unavailable' => [
        fn () => Http::response(['message' => 'Unavailable'], 503),
        AiClassifierUnavailable::class,
    ],
]);

test('a malformed Category reference is preserved as an invalid Category result', function (mixed $categoryPath) {
    Http::fake([
        'https://classifier.example.test/v1/classify' => Http::response([
            'category_path' => $categoryPath,
            'confidence' => 87,
            'explanation' => 'The response names no valid Category path.',
        ]),
    ]);

    $result = app(AiClassifier::class)->classify(aiClassifierInput());

    expect($result)
        ->categoryPath->toBeNull()
        ->confidence->toBe(87);
})->with([
    'missing' => null,
    'numeric' => 42,
    'blank' => '   ',
]);

test('the HTTP classifier accepts a structured missing Category proposal', function () {
    Http::fake([
        'https://classifier.example.test/v1/classify' => Http::response([
            'category_path' => null,
            'category_proposal' => [
                'name' => '  Bakeries ',
                'parent_category_path' => ' Food ',
                'description' => ' Bread, pastries, and baked goods. ',
                'examples' => [' Neighborhood bakery ', 'Neighborhood bakery'],
            ],
            'confidence' => 91,
            'explanation' => 'No active Category adequately fits.',
        ]),
    ]);

    $result = app(AiClassifier::class)->classify(aiClassifierInput());

    expect($result)
        ->categoryPath->toBeNull()
        ->categoryProposal->name->toBe('Bakeries')
        ->categoryProposal->parentCategoryPath->toBe('Food')
        ->categoryProposal->description->toBe('Bread, pastries, and baked goods.')
        ->categoryProposal->examples->toBe(['Neighborhood bakery']);
});

test('ambiguous or malformed missing Category proposals fail closed', function (array $response) {
    Http::fake([
        'https://classifier.example.test/v1/classify' => Http::response([
            'confidence' => 91,
            'explanation' => 'A proposal was returned.',
            ...$response,
        ]),
    ]);

    expect(fn () => app(AiClassifier::class)->classify(aiClassifierInput()))
        ->toThrow(AiClassifierUnavailable::class);
})->with([
    'existing path and proposal together' => [[
        'category_path' => 'Food > Groceries',
        'category_proposal' => [
            'name' => 'Bakeries',
            'parent_category_path' => 'Food',
            'description' => null,
            'examples' => [],
        ],
    ]],
    'blank proposal name' => [[
        'category_path' => null,
        'category_proposal' => [
            'name' => '   ',
            'parent_category_path' => null,
            'description' => null,
            'examples' => [],
        ],
    ]],
    'invalid examples' => [[
        'category_path' => null,
        'category_proposal' => [
            'name' => 'Bakeries',
            'parent_category_path' => null,
            'description' => null,
            'examples' => ['Valid', 42],
        ],
    ]],
]);

test('malformed confidence and explanation responses are rejected', function (array $response) {
    Http::fake([
        'https://classifier.example.test/v1/classify' => Http::response($response),
    ]);

    expect(fn () => app(AiClassifier::class)->classify(aiClassifierInput()))
        ->toThrow(AiClassifierUnavailable::class);
})->with([
    'confidence' => [[
        'category_path' => 'Food > Groceries',
        'confidence' => 101,
        'explanation' => 'Invalid confidence.',
    ]],
    'explanation' => [[
        'category_path' => 'Food > Groceries',
        'confidence' => 87,
        'explanation' => '',
    ]],
]);

function aiClassifierInput(): AiClassificationInput
{
    return new AiClassificationInput(
        merchantDescription: 'café central',
        kind: 'purchase',
        amountMinor: 12_450,
        currency: 'PEN',
        categories: [
            [
                'path' => 'Food > Groceries',
                'description' => 'Ingredients and household staples.',
                'examples' => ['Supermarkets'],
            ],
        ],
    );
}

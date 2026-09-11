<?php

namespace App\Http\Controllers;

use App\Actions\Trends\ReadTrends;
use App\Currency;
use App\Http\Requests\IndexTrendsRequest;
use Inertia\Inertia;
use Inertia\Response;

class TrendsController extends Controller
{
    public function __construct(private ReadTrends $readTrends) {}

    public function __invoke(IndexTrendsRequest $request): Response
    {
        $filters = $request->validated();
        $currency = isset($filters['currency'])
            ? Currency::from($filters['currency'])
            : null;

        return Inertia::render('trends/index', $this->readTrends->handle($request->user(), $currency, $filters));
    }
}

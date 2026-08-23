<?php

namespace App\Http\Controllers;

use App\Actions\Breakdown\ReadBreakdown;
use App\Currency;
use App\Http\Requests\IndexBreakdownRequest;
use Inertia\Inertia;
use Inertia\Response;

class BreakdownController extends Controller
{
    public function __construct(private ReadBreakdown $readBreakdown) {}

    public function __invoke(IndexBreakdownRequest $request): Response
    {
        $validated = $request->validated();

        return Inertia::render('breakdown/index', $this->readBreakdown->handle(
            owner: $request->user(),
            currency: Currency::from($validated['currency'] ?? Currency::Pen->value),
            filters: $validated,
        ));
    }
}

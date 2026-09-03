<?php

namespace App\Http\Controllers;

use App\Actions\Breakdown\ReadBreakdown;
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
            filters: $validated,
        ));
    }
}

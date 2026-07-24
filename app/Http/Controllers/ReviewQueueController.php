<?php

namespace App\Http\Controllers;

use App\Actions\Ledger\ReadReviewQueue;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReviewQueueController extends Controller
{
    public function __construct(private ReadReviewQueue $readReviewQueue) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render(
            'review-queue/index',
            [
                ...$this->readReviewQueue->handle($request->user()),
                'stale_transaction' => $request->session()->get('stale_transaction'),
            ],
        );
    }
}

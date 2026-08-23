<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

abstract class Controller
{
    protected function redirectToWorkspace(string $fallbackRoute): RedirectResponse
    {
        $previousUrl = request()->header('referer');
        $workspaceUrls = [
            route('breakdown.index'),
            route('transactions.index'),
            route('review_queue.index'),
            route('categories.index'),
        ];

        if (
            request()->header('X-Inertia') === 'true'
            && is_string($previousUrl)
            && Str::startsWith($previousUrl, $workspaceUrls)
        ) {
            return redirect()->to($previousUrl);
        }

        return to_route($fallbackRoute);
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\Retention\RestoreDeletedReceiptBreakdown;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReceiptBreakdownTrashRestorationController extends Controller
{
    public function __construct(
        private RestoreDeletedReceiptBreakdown $restoreDeletedReceiptBreakdown,
    ) {}

    public function __invoke(Request $request, string $deletionId): RedirectResponse
    {
        $this->restoreDeletedReceiptBreakdown->handle($request->user(), $deletionId);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Receipt Breakdown restored from trash.'),
        ]);

        return back();
    }
}

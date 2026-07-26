<?php

namespace App\Http\Controllers;

use App\Actions\Ledger\ReopenSuspectedDuplicate;
use App\Actions\Ledger\ResolveSuspectedDuplicate;
use App\Exceptions\IdempotencyKeyConflict;
use App\Exceptions\StaleTransactionRevision;
use App\Http\Requests\ReopenSuspectedDuplicateRequest;
use App\Http\Requests\ResolveSuspectedDuplicateRequest;
use App\Models\SuspectedDuplicate;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use InvalidArgumentException;

class SuspectedDuplicateResolutionController extends Controller
{
    public function __construct(
        private ResolveSuspectedDuplicate $resolveSuspectedDuplicate,
        private ReopenSuspectedDuplicate $reopenSuspectedDuplicate,
    ) {}

    public function store(
        ResolveSuspectedDuplicateRequest $request,
        SuspectedDuplicate $suspectedDuplicate,
    ): RedirectResponse {
        $validated = $request->validated();
        $survivor = Transaction::query()
            ->whereKey((int) $validated['survivor_transaction_id'])
            ->firstOrFail();

        try {
            $this->resolveSuspectedDuplicate->handle(
                owner: $request->user(),
                suspectedDuplicate: $suspectedDuplicate,
                survivor: $survivor,
                expectedSuspectedDuplicateRevision: (int) $validated['expected_suspected_duplicate_revision'],
                expectedFirstTransactionRevision: (int) $validated['expected_first_transaction_revision'],
                expectedSecondTransactionRevision: (int) $validated['expected_second_transaction_revision'],
                expectedFirstSourceReferenceFingerprint: $validated['expected_first_source_reference_fingerprint'],
                expectedSecondSourceReferenceFingerprint: $validated['expected_second_source_reference_fingerprint'],
                idempotencyKey: $validated['idempotency_key'],
            );
        } catch (StaleTransactionRevision|IdempotencyKeyConflict|InvalidArgumentException $exception) {
            Inertia::flash('suspected_duplicate_error', $exception->getMessage());

            return back()->withErrors([
                'suspected_duplicate_resolution' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Suspected Duplicate resolved.'),
        ]);

        return $this->redirectToWorkspace('review_queue.index');
    }

    public function destroy(
        ReopenSuspectedDuplicateRequest $request,
        SuspectedDuplicate $suspectedDuplicate,
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $this->reopenSuspectedDuplicate->handle(
                owner: $request->user(),
                suspectedDuplicate: $suspectedDuplicate,
                expectedSuspectedDuplicateRevision: (int) $validated['expected_suspected_duplicate_revision'],
                expectedFirstTransactionRevision: (int) $validated['expected_first_transaction_revision'],
                expectedSecondTransactionRevision: (int) $validated['expected_second_transaction_revision'],
                idempotencyKey: $validated['idempotency_key'],
            );
        } catch (StaleTransactionRevision|IdempotencyKeyConflict|InvalidArgumentException $exception) {
            Inertia::flash('suspected_duplicate_error', $exception->getMessage());

            return back()->withErrors([
                'suspected_duplicate_resolution' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Suspected Duplicate reopened.'),
        ]);

        return $this->redirectToWorkspace('review_queue.index');
    }
}

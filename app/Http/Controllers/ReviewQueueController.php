<?php

namespace App\Http\Controllers;

use App\Models\LineItem;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReviewQueueController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $item = $request->query('item');
        $selected = $request->query('selected');
        $transactionId = null;

        if (is_string($item) && preg_match('/^transaction:([1-9][0-9]*)$/D', $item, $matches) === 1) {
            $transactionId = (int) $matches[1];
        } elseif (is_string($item) && preg_match('/^line-item:([1-9][0-9]*)$/D', $item, $matches) === 1) {
            $lineItem = LineItem::query()
                ->whereHas('receiptBreakdown.transaction', fn ($query) => $query->whereBelongsTo($request->user(), 'owner'))
                ->with('receiptBreakdown:id,transaction_id')
                ->find((int) $matches[1]);
            $transactionId = $lineItem?->receiptBreakdown?->transaction_id;
        } elseif (is_string($selected) && preg_match('/^[1-9][0-9]*$/D', $selected) === 1) {
            $transactionId = (int) $selected;
        }

        if ($transactionId !== null && Transaction::query()
            ->whereBelongsTo($request->user(), 'owner')
            ->whereKey($transactionId)
            ->exists()) {
            return to_route('transactions.index', ['selected' => $transactionId]);
        }

        return to_route('transactions.index');
    }
}

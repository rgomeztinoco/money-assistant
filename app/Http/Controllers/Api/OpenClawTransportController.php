<?php

namespace App\Http\Controllers\Api;

use App\Actions\Ledger\ReadTransactionForOpenClaw;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class OpenClawTransportController extends Controller
{
    public function __construct(
        private ReadTransactionForOpenClaw $readTransaction,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $owner = User::query()->first();
        $transactionId = $request->attributes->get('openclaw.transaction_id');
        $transaction = $owner === null || ! is_int($transactionId)
            ? null
            : $this->readTransaction->handle($owner, $transactionId);

        if ($transaction === null) {
            $request->attributes->set('openclaw.audit.outcome', 'not_found');

            return response()->json(
                ['message' => 'Transaction not found.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $request->attributes->set('openclaw.audit.outcome', 'success');
        $request->attributes->set('openclaw.audit.result_count', 1);

        return response()->json([
            'schema_version' => 1,
            'transaction' => $transaction,
        ]);
    }
}

<?php

namespace App\Mcp\Tools;

use App\Actions\Categorization\AssignCategoryToTransaction;
use App\Actions\Ledger\ReadAgentTransactions;
use App\Mcp\TransactionSchema;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('assign_transaction_category')]
#[Description('Assign, replace, or clear the direct fallback Category of an active Spending or Refund. category_id is required; use explicit null to clear. Resolve exact IDs through read tools and choose an active owner Category. This records owner assignment and does not change a Merchant Rule or an existing Receipt Breakdown. Returned effective allocations still come from that breakdown when present.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent]
#[IsOpenWorld(false)]
class AssignTransactionCategory extends Tool
{
    public function handle(Request $request, AssignCategoryToTransaction $assign, ReadAgentTransactions $read): ResponseFactory|Response
    {
        $owner = $request->user();
        assert($owner instanceof User);
        $rules = ['id' => ['required', 'integer', 'min:1'], 'category_id' => ['present', 'nullable', 'integer', 'min:1']];
        Validator::validate(['arguments' => $request->all()], ['arguments' => 'array:'.implode(',', array_keys($rules))]);
        $data = $request->validate($rules);
        $transaction = Transaction::query()->whereBelongsTo($owner, 'owner')->find((int) $data['id']);
        if ($transaction === null) {
            return Response::error('Transaction not found.');
        }
        $saved = $assign->handle($owner, $transaction->id, isset($data['category_id']) ? (int) $data['category_id'] : null);

        return Response::structured(['transaction' => $read->find($owner, $saved->id)]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->min(1)->required(), 'category_id' => $schema->integer()->min(1)->nullable()->required()];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['transaction' => TransactionSchema::transaction($schema)->required()];
    }
}

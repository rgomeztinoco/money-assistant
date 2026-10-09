<?php

namespace App\Mcp\Tools;

use App\Actions\Ledger\ReadAgentTransactions;
use App\Actions\ReceiptReconciliation\SaveReceiptBreakdown;
use App\Mcp\TransactionSchema;
use App\Models\Transaction;
use App\Models\User;
use App\Rules\ExactMinorAmount;
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

#[Name('set_transaction_category_split')]
#[Description('Set or replace the complete Category split of an active Spending or Refund using synthetic Receipt Breakdown Line Items. Each allocation requires a nonzero signed exact amount_minor and an active owner category_id or explicit null for Uncategorized. Supply 1 to 200 allocations that sum exactly to the unchanged Transaction amount. Never invent a remainder. This replaces the entire existing Receipt Breakdown and discards descriptions, quantities, and unit prices. Before replacing existing receipt detail, explain that loss and establish explicit owner replacement intent. The direct Category remains fallback.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent]
#[IsOpenWorld(false)]
class SetTransactionCategorySplit extends Tool
{
    public function handle(Request $request, SaveReceiptBreakdown $save, ReadAgentTransactions $read): ResponseFactory|Response
    {
        $owner = $request->user();
        assert($owner instanceof User);
        $rules = [
            'id' => ['required', 'integer', 'min:1'],
            'allocations' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'allocations.*' => ['required', 'array:amount_minor,category_id'],
            'allocations.*.amount_minor' => ['required', new ExactMinorAmount(9_007_199_254_740_991, signed: true)],
            'allocations.*.category_id' => ['present', 'nullable', 'integer', 'min:1'],
        ];
        Validator::validate(['arguments' => $request->all()], ['arguments' => 'array:id,allocations']);
        $data = $request->validate($rules);
        $transaction = Transaction::query()->whereBelongsTo($owner, 'owner')->find((int) $data['id']);
        if ($transaction === null) {
            return Response::error('Transaction not found.');
        }
        $lineItems = [];
        foreach ($data['allocations'] as $index => $allocation) {
            $lineItems[] = [
                'description' => 'Category allocation '.($index + 1),
                'quantity' => null, 'unit_price_minor' => null,
                'line_total_minor' => (int) $allocation['amount_minor'],
                'category_id' => isset($allocation['category_id']) ? (int) $allocation['category_id'] : null,
            ];
        }
        $save->handle($owner, $transaction, $lineItems);

        return Response::structured(['transaction' => $read->find($owner, $transaction->id)]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->min(1)->required(),
            'allocations' => $schema->array()->min(1)->max(200)->items($schema->object([
                'amount_minor' => $schema->string()->pattern('^-?[0-9]+$')->description('Nonzero signed minor units, maximum absolute value 9007199254740991.')->required(),
                'category_id' => $schema->integer()->min(1)->nullable()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['transaction' => TransactionSchema::transaction($schema)->required()];
    }
}

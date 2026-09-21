<?php

namespace App\Mcp\Tools;

use App\Actions\Ledger\ReadAgentTransactions;
use App\Mcp\TransactionSchema;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
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

#[Name('get_transaction')]
#[Description('Read one owner Transaction, active or Voided, by ID. Amounts are positive exact minor-unit strings; direction carries account movement. Receipt Breakdown allocations replace the direct Category contribution. Individual Line Items are excluded.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetTransaction extends Tool
{
    public function handle(Request $request, ReadAgentTransactions $transactions): ResponseFactory|Response
    {
        $owner = $request->user();
        assert($owner instanceof User);
        $arguments = $request->validate(['id' => ['required', 'integer', 'min:1']]);
        $transaction = $transactions->find($owner, (int) $arguments['id']);

        return $transaction === null ? Response::error('Transaction not found.') : Response::structured(['transaction' => $transaction]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->min(1)->required()];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['transaction' => TransactionSchema::transaction($schema)->required()];
    }
}

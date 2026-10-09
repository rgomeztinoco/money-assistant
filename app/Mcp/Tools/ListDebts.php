<?php

namespace App\Mcp\Tools;

use App\Actions\Debts\ReadDebts;
use App\Mcp\DebtSchema;
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

#[Name('list_debts')]
#[Description('Read all owner debts, current balances and settlement status, optional monthly targets and full-payment progress for the current Lima calendar month. Keep currencies and outgoing/incoming obligations separate. Amounts are exact minor-unit strings. No writes.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ListDebts extends Tool
{
    public function handle(Request $request, ReadDebts $debts): ResponseFactory
    {
        $owner = $request->user();
        assert($owner instanceof User);

        return Response::structured(['debts' => $debts->handle($owner)]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['debts' => $schema->array()->items(DebtSchema::debt($schema))->required()];
    }
}

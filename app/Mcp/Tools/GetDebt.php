<?php

namespace App\Mcp\Tools;

use App\Actions\Debts\ReadDebts;
use App\Mcp\DebtSchema;
use App\Models\Debt;
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

#[Name('get_debt')]
#[Description('Read an owner debt with its current balance, Lima calendar-month target and full-payment progress, and independent dated history. Voided payments remain in history and do not reduce the balance. Amounts are exact minor-unit strings. No writes.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetDebt extends Tool
{
    public function handle(Request $request, ReadDebts $debts): ResponseFactory|Response
    {
        $owner = $request->user();
        assert($owner instanceof User);
        $arguments = $request->validate(['id' => ['required', 'integer', 'min:1']]);
        $debt = Debt::query()->whereBelongsTo($owner, 'owner')->find((int) $arguments['id']);

        return $debt === null ? Response::error('Debt not found.') : Response::structured(['debt' => $debts->debtData($debt), 'entries' => $debts->entries($debt)]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->min(1)->required()];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['debt' => DebtSchema::debt($schema)->required(), 'entries' => $schema->array()->items(DebtSchema::entry($schema))->required()];
    }
}

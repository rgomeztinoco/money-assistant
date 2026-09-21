<?php

namespace App\Mcp\Tools;

use App\Actions\Ledger\ReadAgentTransactions;
use App\Currency;
use App\Mcp\TransactionSchema;
use App\Models\User;
use App\TransactionKind;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
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

#[Name('list_transactions')]
#[Description('List up to 50 owner Transactions. Active only by default. Normal order is occurred_on descending then ID descending. Amounts are exact minor-unit strings and currencies remain separate. Receipt Breakdown allocations replace the direct Category contribution.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ListTransactions extends Tool
{
    public function handle(Request $request, ReadAgentTransactions $transactions): ResponseFactory|Response
    {
        $owner = $request->user();
        assert($owner instanceof User);
        $filters = $request->validate([
            'updated_since' => ['sometimes', 'date', 'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/'],
            'cursor' => ['sometimes', 'string', 'max:4096'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', ...$request->has('date_from') ? ['after_or_equal:date_from'] : []],
            'currency' => ['sometimes', Rule::enum(Currency::class)],
            'kind' => ['sometimes', Rule::enum(TransactionKind::class)],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:200'],
            'void_state' => ['sometimes', Rule::in(['active', 'voided', 'all'])],
        ]);

        try {
            return Response::structured($transactions->listing($owner, $filters));
        } catch (ModelNotFoundException) {
            return Response::error('Category not found.');
        }
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'updated_since' => $schema->string()->description('Inclusive ISO 8601 instant with timezone. Orders by updated_at ascending, then ID ascending. Deduplicate equal-timestamp boundary records by ID and updated_at.'),
            'cursor' => $schema->string()->description('Opaque continuation cursor. Repeat all original filters unchanged. The cursor preserves as_of; records updated later belong to the next scan.'),
            'date_from' => $schema->string()->description('Inclusive occurrence date, YYYY-MM-DD.'),
            'date_to' => $schema->string()->description('Inclusive occurrence date, YYYY-MM-DD.'),
            'currency' => $schema->string()->enum(Currency::class),
            'kind' => $schema->string()->enum(TransactionKind::class),
            'category_id' => $schema->integer()->min(1)->description('Effective Category allocation. Includes current children of a top-level Category.'),
            'search' => $schema->string()->max(200)->description('Case-insensitive description search.'),
            'void_state' => $schema->string()->enum(['active', 'voided', 'all'])->default('active'),
        ];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'transactions' => $schema->array()->items(TransactionSchema::transaction($schema))->required(),
            'order' => $schema->string()->enum(['occurred_on_desc_id_desc', 'updated_at_asc_id_asc'])->required(),
            'as_of' => $schema->string()->required(),
            'next_cursor' => $schema->string()->nullable()->required(),
            'checkpoint' => $schema->string()->nullable()->description('Available only on the final page. Pass as updated_since for the next inclusive scan.')->required(),
        ];
    }
}

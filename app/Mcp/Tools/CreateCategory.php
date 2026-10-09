<?php

namespace App\Mcp\Tools;

use App\Actions\Categorization\CreateCategory as CreateOwnerCategory;
use App\Mcp\TransactionSchema;
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

#[Name('create_category')]
#[Description('Create a top-level or second-level Category only when the owner explicitly authorizes creation. Use list_categories first to resolve an existing parent ID or matching Category. The parent must be an active owner top-level Category. Active sibling names are unique. This does not edit existing Categories.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class CreateCategory extends Tool
{
    public function handle(Request $request, CreateOwnerCategory $create): ResponseFactory
    {
        $owner = $request->user();
        assert($owner instanceof User);
        $rules = ['name' => ['required', 'string'], 'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1']];
        Validator::validate(['arguments' => $request->all()], ['arguments' => 'array:'.implode(',', array_keys($rules))]);
        $data = $request->validate($rules);
        $category = $create->handle($owner, $data['name'], isset($data['parent_id']) ? (int) $data['parent_id'] : null);

        return Response::structured(['category' => [
            'id' => $category->id, 'name' => $category->name, 'parent_id' => $category->parent_id,
            'archived_at' => $category->archived_at?->toIso8601String(),
        ]]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['name' => $schema->string()->max(255)->required(), 'parent_id' => $schema->integer()->min(1)->nullable()];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['category' => TransactionSchema::category($schema)->required()];
    }
}

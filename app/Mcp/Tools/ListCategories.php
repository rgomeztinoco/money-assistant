<?php

namespace App\Mcp\Tools;

use App\Actions\Categorization\ReadCategoryTaxonomy;
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

#[Name('list_categories')]
#[Description('Read the complete current two-level Category taxonomy, including Archived Categories, without pagination or transaction counts. Historical assignments use current names and parents.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ListCategories extends Tool
{
    public function handle(Request $request, ReadCategoryTaxonomy $taxonomy): ResponseFactory
    {
        $owner = $request->user();
        assert($owner instanceof User);
        $categories = array_map(function (array $category): array {
            unset($category['transaction_count']);
            $category['children'] = array_map(function (array $child): array {
                unset($child['transaction_count']);

                return $child;
            }, $category['children']);

            return $category;
        }, $taxonomy->handle($owner));

        return Response::structured(['categories' => $categories]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        $category = [
            'id' => $schema->integer()->required(),
            'parent_id' => $schema->integer()->nullable()->required(),
            'name' => $schema->string()->required(),
            'archived_at' => $schema->string()->nullable()->required(),
        ];

        return ['categories' => $schema->array()->items($schema->object([
            ...$category,
            'children' => $schema->array()->items($schema->object($category))->required(),
        ]))->required()];
    }
}

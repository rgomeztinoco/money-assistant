<?php

namespace App\Mcp;

use App\CategoryAssignmentProvenance;
use App\Currency;
use App\IncomeSource;
use App\MovementDirection;
use App\TransactionKind;
use App\TransferPurpose;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;

final class TransactionSchema
{
    public static function category(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'id' => $schema->integer()->required(),
            'name' => $schema->string()->required(),
            'parent_id' => $schema->integer()->nullable()->required(),
            'archived_at' => $schema->string()->nullable()->required(),
        ])->withoutAdditionalProperties();
    }

    public static function related(JsonSchema $schema): ObjectType
    {
        return $schema->object(self::relatedProperties($schema))->withoutAdditionalProperties();
    }

    /** @return array<string, Type> */
    private static function relatedProperties(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'occurred_on' => $schema->string()->required(),
            'description' => $schema->string()->required(),
            'amount_minor' => $schema->string()->pattern('^[0-9]+$')->required(),
            'currency' => $schema->string()->enum(Currency::class)->required(),
            'kind' => $schema->string()->enum(TransactionKind::class)->required(),
            'direction' => $schema->string()->enum(MovementDirection::class)->required(),
            'voided_at' => $schema->string()->nullable()->required(),
        ];
    }

    public static function transaction(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            ...self::relatedProperties($schema),
            'income_source' => $schema->string()->enum([...array_column(IncomeSource::cases(), 'value'), null])->nullable()->required(),
            'transfer_purpose' => $schema->string()->enum([...array_column(TransferPurpose::cases(), 'value'), null])->nullable()->required(),
            'confirmed_at' => $schema->string()->required(),
            'updated_at' => $schema->string()->required(),
            'category' => self::category($schema)->nullable()->required(),
            'category_assignment_provenance' => $schema->string()->enum([...array_column(CategoryAssignmentProvenance::cases(), 'value'), null])->nullable()->required(),
            'review_state' => $schema->string()->enum(['outstanding', 'clear'])->required(),
            'original_spending' => self::related($schema)->nullable()->required(),
            'linked_refunds' => $schema->array()->items(self::related($schema))->required(),
            'category_allocations' => $schema->array()->items($schema->object([
                'category' => self::category($schema)->nullable()->required(),
                'amount_minor' => $schema->string()->pattern('^-?[0-9]+$')->required(),
            ])->withoutAdditionalProperties())->required(),
        ])->withoutAdditionalProperties();
    }
}

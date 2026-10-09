<?php

namespace App\Mcp;

use App\Currency;
use App\DebtDirection;
use App\DebtEntryKind;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;

final class DebtSchema
{
    public static function debt(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'id' => $schema->integer()->required(),
            'name' => $schema->string()->required(),
            'counterparty' => $schema->string()->required(),
            'direction' => $schema->string()->enum(DebtDirection::class)->required(),
            'currency' => $schema->string()->enum(Currency::class)->required(),
            'opening_balance_minor' => $schema->string()->pattern('^[0-9]+$')->required(),
            'opened_on' => $schema->string()->required(),
            'balance_minor' => $schema->string()->pattern('^-?[0-9]+$')->required(),
            'status' => $schema->string()->enum(['active', 'settled'])->required(),
            'monthly_target_minor' => $schema->string()->nullable()->required(),
            'monthly_paid_minor' => $schema->string()->pattern('^[0-9]+$')->required(),
            'target_month' => $schema->string()->required(),
        ])->withoutAdditionalProperties();
    }

    public static function entry(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'id' => $schema->integer()->required(),
            'kind' => $schema->string()->enum(DebtEntryKind::class)->required(),
            'amount_minor' => $schema->string()->pattern('^-?[0-9]+$')->required(),
            'occurred_on' => $schema->string()->required(),
            'reason' => $schema->string()->nullable()->required(),
            'transaction_id' => $schema->integer()->nullable()->required(),
            'principal_minor' => $schema->string()->nullable()->required(),
            'interest_minor' => $schema->string()->pattern('^[0-9]+$')->required(),
            'voided' => $schema->boolean()->required(),
        ])->withoutAdditionalProperties();
    }
}

<?php

namespace App\Mcp\Tools;

use App\Actions\YearlyPayments\ReadYearlyPaymentPlan;
use App\Currency;
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

#[Name('get_yearly_payment_plan')]
#[Description('Read the owner complete yearly-payment plan, including paused commitments, exact annual and monthly targets, manual PEN per USD estimate, upcoming payments and calculation assumptions. These are planning targets, not money saved, paid bills, or money safe to spend. Dates do not affect targets or renew automatically. No records are changed.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetYearlyPaymentPlan extends Tool
{
    public function handle(Request $request, ReadYearlyPaymentPlan $plan): ResponseFactory
    {
        $owner = $request->user();
        assert($owner instanceof User);

        return Response::structured($plan->handle($owner));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        $commitment = $schema->object([
            'id' => $schema->integer()->required(),
            'name' => $schema->string()->required(),
            'amount_minor' => $schema->string()->pattern('^[0-9]+$')->required(),
            'currency' => $schema->string()->enum(Currency::class)->required(),
            'cushion_minor' => $schema->string()->pattern('^[0-9]+$')->required(),
            'target_minor' => $schema->string()->pattern('^[0-9]+$')->required(),
            'expected_due_on' => $schema->string()->nullable()->required(),
            'is_active' => $schema->boolean()->required(),
        ])->withoutAdditionalProperties();

        return [
            'commitments' => $schema->array()->items($commitment)->required(),
            'native_targets' => $schema->array()->items($schema->object([
                'currency' => $schema->string()->enum(Currency::class)->required(),
                'annual_target_minor' => $schema->string()->pattern('^[0-9]+$')->required(),
                'monthly_recommendation_minor' => $schema->string()->pattern('^[0-9]+$')->required(),
            ])->withoutAdditionalProperties())->required(),
            'combined_estimate' => $schema->object([
                'status' => $schema->string()->enum(['available', 'unavailable'])->required(),
                'currency' => $schema->string()->enum(['PEN'])->required(),
                'annual_target_minor' => $schema->string()->pattern('^[0-9]+$')->nullable()->required(),
                'monthly_recommendation_minor' => $schema->string()->pattern('^[0-9]+$')->nullable()->required(),
                'unavailable_reason' => $schema->string()->nullable()->required(),
            ])->withoutAdditionalProperties()->required(),
            'planning_rate' => $schema->object([
                'pen_per_usd' => $schema->string()->pattern('^[0-9]+(?:\.[0-9]+)?$')->nullable()->required(),
                'direction' => $schema->string()->enum(['PEN per USD'])->required(),
                'source' => $schema->string()->enum(['manual'])->required(),
            ])->withoutAdditionalProperties()->required(),
            'upcoming_commitments' => $schema->array()->items($commitment)->required(),
            'calculation_date' => $schema->string()->required(),
            'timezone' => $schema->string()->required(),
            'assumptions' => $schema->object([
                'target_scope' => $schema->string()->required(),
                'monthly_rounding' => $schema->string()->required(),
                'conversion_rounding' => $schema->string()->required(),
                'due_dates' => $schema->string()->required(),
                'planning_only' => $schema->string()->required(),
            ])->withoutAdditionalProperties()->required(),
        ];
    }
}

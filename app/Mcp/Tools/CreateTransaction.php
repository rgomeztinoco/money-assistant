<?php

namespace App\Mcp\Tools;

use App\Actions\Ledger\ReadAgentTransactions;
use App\Actions\Ledger\RecordManualTransaction;
use App\Actions\Ledger\UpdateTransaction;
use App\Currency;
use App\IncomeSource;
use App\Mcp\TransactionSchema;
use App\Models\Transaction;
use App\Models\User;
use App\MovementDirection;
use App\Rules\ExactMinorAmount;
use App\Rules\TransactionClassificationRules;
use App\TransactionKind;
use App\TransferPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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

#[Name('create_transaction')]
#[Description('Immediately record a confirmed Spending, Refund, Income, or Transfer. amount_minor is a positive exact minor-unit decimal string. Currency defaults to PEN. Income requires income_source; Transfer requires transfer_purpose and direction. Direction is independent of Kind. Omitting category_id permits Merchant Rule matching; explicit null suppresses it. Refund may link to an active owner Spending in the same Currency, or remain explicitly unlinked. Resolve calendar dates in the reporting timezone. After an uncertain transport failure, read to verify the outcome before retrying creation.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class CreateTransaction extends Tool
{
    public function handle(Request $request, RecordManualTransaction $record, UpdateTransaction $edit, ReadAgentTransactions $read): ResponseFactory
    {
        $owner = $request->user();
        assert($owner instanceof User);
        $request->merge(['currency' => $request->get('currency', 'PEN')]);
        if (! array_key_exists('direction', $request->all()) && $request->get('kind') !== TransactionKind::Transfer->value) {
            $request->merge(['direction' => in_array($request->get('kind'), ['refund', 'income'], true) ? 'credit' : 'debit']);
        }

        $rules = [
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'amount_minor' => ['required', new ExactMinorAmount],
            'currency' => ['required', Rule::enum(Currency::class)],
            ...TransactionClassificationRules::fields($request->get('kind')),
            'direction' => ['required', Rule::enum(MovementDirection::class)],
            'description' => ['required', 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'original_spending_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
        if (! in_array($request->get('kind'), ['spending', 'refund'], true)) {
            $rules['category_id'] = ['missing'];
        }
        if ($request->get('kind') !== 'refund') {
            $rules['original_spending_id'] = ['missing'];
        }
        if ($request->get('kind') !== 'income') {
            $rules['income_source'] = ['missing'];
        }
        if ($request->get('kind') !== 'transfer') {
            $rules['transfer_purpose'] = ['missing'];
        }
        Validator::validate(['arguments' => $request->all()], ['arguments' => 'array:'.implode(',', array_keys($rules))]);
        $data = $request->validate($rules);

        $transaction = DB::transaction(function () use ($owner, $data, $record, $edit): Transaction {
            $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : null;
            $transaction = $record->handle(
                owner: $owner,
                occurredOn: CarbonImmutable::parse($data['occurred_on'], config('app.reporting_timezone')),
                amountMinor: (int) $data['amount_minor'],
                currency: Currency::from($data['currency']),
                kind: TransactionKind::from($data['kind']),
                description: $data['description'],
                direction: MovementDirection::from($data['direction']),
                incomeSource: isset($data['income_source']) ? IncomeSource::from($data['income_source']) : null,
                transferPurpose: isset($data['transfer_purpose']) ? TransferPurpose::from($data['transfer_purpose']) : null,
                categoryId: $categoryId,
                categorySpecified: array_key_exists('category_id', $data),
            );
            if (isset($data['original_spending_id'])) {
                $transaction = $edit->handle(
                    $owner, $transaction, $transaction->occurred_on, $transaction->amount_minor,
                    $transaction->currency, $transaction->kind, $transaction->direction, $transaction->description,
                    $transaction->income_source, $transaction->transfer_purpose, null, null,
                    $transaction->category_id, (int) $data['original_spending_id'], false,
                );
            }

            return $transaction;
        }, 3);

        return Response::structured(['transaction' => $read->find($owner, $transaction->id)]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'occurred_on' => $schema->string()->pattern('^[0-9]{4}-[0-9]{2}-[0-9]{2}$')->required(),
            'amount_minor' => $schema->string()->pattern('^[0-9]+$')->description('Positive minor units, maximum '.PHP_INT_MAX.'.')->required(),
            'currency' => $schema->string()->enum(Currency::class)->default('PEN'),
            'kind' => $schema->string()->enum(TransactionKind::class)->required(),
            'description' => $schema->string()->max(255)->required(),
            'direction' => $schema->string()->enum(MovementDirection::class),
            'income_source' => $schema->string()->enum(IncomeSource::class),
            'transfer_purpose' => $schema->string()->enum(TransferPurpose::class),
            'category_id' => $schema->integer()->min(1)->nullable(),
            'original_spending_id' => $schema->integer()->min(1)->nullable(),
        ];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['transaction' => TransactionSchema::transaction($schema)->required()];
    }
}

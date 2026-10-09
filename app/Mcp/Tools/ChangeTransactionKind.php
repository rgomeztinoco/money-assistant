<?php

namespace App\Mcp\Tools;

use App\Actions\Ledger\ReadAgentTransactions;
use App\Actions\Ledger\UpdateTransaction;
use App\IncomeSource;
use App\Mcp\TransactionSchema;
use App\Models\Transaction;
use App\Models\User;
use App\Rules\TransactionClassificationRules;
use App\TransactionKind;
use App\TransferPurpose;
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

#[Name('change_transaction_kind')]
#[Description('Change only the Kind and applicable classification of an active owner Transaction. Income requires income_source; Transfer requires transfer_purpose. The current Kind may be submitted to update its details or link a Refund. An omitted original_spending_id preserves an existing Refund link; explicit null clears it. Preserve the recorded movement and compatible Categories and Receipt Breakdowns. Converting to Income or Transfer removes Categories and the entire Receipt Breakdown; explain those effects and establish owner intent first. Active linked Refunds block converting their original Spending. Never modify related Refunds to bypass that block.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ChangeTransactionKind extends Tool
{
    public function handle(Request $request, UpdateTransaction $edit, ReadAgentTransactions $read): ResponseFactory|Response
    {
        $owner = $request->user();
        assert($owner instanceof User);
        $rules = [
            'id' => ['required', 'integer', 'min:1'],
            ...TransactionClassificationRules::fields($request->get('kind')),
            'original_spending_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
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
        $transaction = Transaction::query()->whereBelongsTo($owner, 'owner')->find((int) $data['id']);
        if ($transaction === null) {
            return Response::error('Transaction not found.');
        }
        $saved = $edit->changeKind(
            $owner, $transaction->id, TransactionKind::from($data['kind']),
            isset($data['income_source']) ? IncomeSource::from($data['income_source']) : null,
            isset($data['transfer_purpose']) ? TransferPurpose::from($data['transfer_purpose']) : null,
            isset($data['original_spending_id']) ? (int) $data['original_spending_id'] : null,
            array_key_exists('original_spending_id', $data),
        );

        return Response::structured(['transaction' => $read->find($owner, $saved->id)]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->min(1)->required(),
            'kind' => $schema->string()->enum(TransactionKind::class)->required(),
            'income_source' => $schema->string()->enum(IncomeSource::class),
            'transfer_purpose' => $schema->string()->enum(TransferPurpose::class),
            'original_spending_id' => $schema->integer()->min(1)->nullable(),
        ];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['transaction' => TransactionSchema::transaction($schema)->required()];
    }
}

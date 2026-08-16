<?php

namespace App\Actions\StatementImports;

use App\ExactInteger;
use App\Models\StatementImport;
use App\Models\StatementMovement;
use App\Models\User;

final class ReadStatementImport
{
    /** @return array<string, mixed> */
    public function handle(User $owner, StatementImport $statementImport): array
    {
        $statementImport = StatementImport::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereKey($statementImport->getKey())
            ->select([
                'id',
                'user_id',
                'financial_statement_format',
                'parser_version',
                'period_start',
                'period_end',
                'instrument_label',
                'instrument_last_four',
                'reconciliation_values',
                'movement_count',
                'confirmed_at',
            ])
            ->with([
                'movements' => fn ($query) => $query
                    ->select([
                        'id',
                        'statement_import_id',
                        'transaction_id',
                        'position',
                        'occurred_on',
                        'amount_minor',
                        'currency',
                        'direction',
                        'classification',
                        'description',
                    ])
                    ->with(['transaction:id,kind,voided_at,category_id', 'transaction.category:id,name'])
                    ->orderBy('position'),
            ])
            ->firstOrFail();

        return [
            'id' => $statementImport->id,
            'financial_statement_format' => $statementImport->financial_statement_format->value,
            'parser_version' => $statementImport->parser_version,
            'period_start' => $statementImport->period_start->toDateString(),
            'period_end' => $statementImport->period_end->toDateString(),
            'instrument_label' => $statementImport->instrument_label,
            'instrument_last_four' => $statementImport->instrument_last_four,
            'movement_count' => $statementImport->movement_count,
            'confirmed_at' => $statementImport->confirmed_at->toIso8601String(),
            'reconciliation' => $statementImport->reconciliation_values,
            'summary' => $this->summary($statementImport),
            'movements' => $statementImport->movements
                ->map(fn (StatementMovement $movement): array => [
                    'id' => $movement->id,
                    'position' => $movement->position,
                    'occurred_on' => $movement->occurred_on->toDateString(),
                    'amount_minor' => (string) $movement->amount_minor,
                    'currency' => $movement->currency->value,
                    'direction' => $movement->direction->value,
                    'classification' => $movement->classification->value,
                    'description' => $movement->description,
                    'transaction' => $movement->transaction === null ? null : [
                        'id' => $movement->transaction->id,
                        'kind' => $movement->transaction->kind->value,
                        'voided_at' => $movement->transaction->voided_at?->toIso8601String(),
                        'category' => $movement->transaction->category === null ? null : [
                            'id' => $movement->transaction->category->id,
                            'name' => $movement->transaction->category->name,
                        ],
                    ],
                ])
                ->all(),
        ];
    }

    /** @return array<string, array<string, string>> */
    private function summary(StatementImport $statementImport): array
    {
        $summary = [];

        foreach (['PEN', 'USD'] as $currency) {
            $summary[$currency] = [
                'spending_minor' => '0',
                'refunds_minor' => '0',
                'income_minor' => '0',
                'transfers_in_minor' => '0',
                'transfers_out_minor' => '0',
                'savings_deposits_minor' => '0',
                'savings_withdrawals_minor' => '0',
                'net_savings_minor' => '0',
            ];
        }

        foreach ($statementImport->movements as $movement) {
            $currency = $movement->currency->value;
            $amount = ExactInteger::from($movement->amount_minor);
            $key = $movement->classification->summaryKey($movement->direction);

            if ($key !== null) {
                $summary[$currency][$key] = ExactInteger::from($summary[$currency][$key])
                    ->add($amount)
                    ->value();
            }
        }

        foreach (['PEN', 'USD'] as $currency) {
            $summary[$currency]['net_savings_minor'] = ExactInteger::from($summary[$currency]['savings_deposits_minor'])
                ->subtract(ExactInteger::from($summary[$currency]['savings_withdrawals_minor']))
                ->value();
        }

        return $summary;
    }
}

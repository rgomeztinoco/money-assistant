<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('transactions')
                ->where('kind', 'purchase')
                ->update(['kind' => 'spending']);
            DB::table('transactions')
                ->where('kind', 'refund')
                ->update(['direction' => 'credit']);
            DB::table('merchant_rules')
                ->where('transaction_kind', 'purchase')
                ->update(['transaction_kind' => 'spending']);

            $this->statementMovementsQuery()->chunkById(
                200,
                function (Collection $movements): void {
                    foreach ($movements as $movement) {
                        $meaning = $this->movementMeaning($movement->classification);

                        if ($meaning === null) {
                            continue;
                        }

                        $attributes = [
                            'kind' => $meaning['kind'],
                            'direction' => $movement->direction,
                            'income_source' => $meaning['income_source'],
                            'transfer_purpose' => $meaning['transfer_purpose'],
                        ];

                        if ($movement->transaction_id !== null) {
                            DB::table('transactions')
                                ->where('id', $movement->transaction_id)
                                ->update($attributes);

                            continue;
                        }

                        $transactionId = DB::table('transactions')->insertGetId([
                            'user_id' => $movement->user_id,
                            'occurred_on' => $movement->occurred_on,
                            'amount_minor' => $movement->amount_minor,
                            'currency' => $movement->currency,
                            ...$attributes,
                            'merchant_description' => $movement->description,
                            'payment_instrument_label' => $movement->instrument_label,
                            'payment_instrument_last_four' => $movement->instrument_last_four,
                            'confirmed_at' => $movement->confirmed_at,
                            'created_at' => $movement->created_at,
                            'updated_at' => $movement->updated_at,
                        ]);

                        DB::table('statement_movements')
                            ->where('id', $movement->id)
                            ->update(['transaction_id' => $transactionId]);
                    }
                },
                'statement_movements.id',
                'id',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            $this->statementMovementsQuery()->chunkById(
                200,
                function (Collection $movements): void {
                    foreach ($movements as $movement) {
                        if ($movement->transaction_id === null) {
                            continue;
                        }

                        if (in_array($movement->classification, ['income', 'transfer', 'card_payment', 'already_recorded', 'needs_classification'], true)) {
                            DB::table('statement_movements')
                                ->where('id', $movement->id)
                                ->update(['transaction_id' => null]);
                            DB::table('transactions')
                                ->where('id', $movement->transaction_id)
                                ->delete();

                            continue;
                        }

                        $legacyKind = $movement->classification === 'refund'
                            || ($movement->classification === 'savings' && $movement->direction === 'credit')
                                ? 'refund'
                                : 'purchase';

                        DB::table('transactions')
                            ->where('id', $movement->transaction_id)
                            ->update([
                                'kind' => $legacyKind,
                                'income_source' => null,
                                'transfer_purpose' => null,
                            ]);
                    }
                },
                'statement_movements.id',
                'id',
            );

            DB::table('transactions')
                ->where('kind', 'spending')
                ->update(['kind' => 'purchase']);
            DB::table('transactions')
                ->whereIn('kind', ['income', 'transfer'])
                ->where('direction', 'credit')
                ->update([
                    'kind' => 'refund',
                    'income_source' => null,
                    'transfer_purpose' => null,
                ]);
            DB::table('transactions')
                ->whereIn('kind', ['income', 'transfer'])
                ->update([
                    'kind' => 'purchase',
                    'income_source' => null,
                    'transfer_purpose' => null,
                ]);
            DB::table('merchant_rules')
                ->where('transaction_kind', 'spending')
                ->update(['transaction_kind' => 'purchase']);
        });
    }

    private function statementMovementsQuery(): Builder
    {
        return DB::table('statement_movements')
            ->join('statement_imports', 'statement_imports.id', '=', 'statement_movements.statement_import_id')
            ->select([
                'statement_movements.id',
                'statement_movements.transaction_id',
                'statement_movements.occurred_on',
                'statement_movements.amount_minor',
                'statement_movements.currency',
                'statement_movements.direction',
                'statement_movements.classification',
                'statement_movements.description',
                'statement_movements.instrument_label',
                'statement_movements.instrument_last_four',
                'statement_movements.created_at',
                'statement_movements.updated_at',
                'statement_imports.user_id',
                'statement_imports.confirmed_at',
            ]);
    }

    /** @return array{kind: string, income_source: string|null, transfer_purpose: string|null}|null */
    private function movementMeaning(string $classification): ?array
    {
        return match ($classification) {
            'purchase', 'fee', 'tax' => [
                'kind' => 'spending',
                'income_source' => null,
                'transfer_purpose' => null,
            ],
            'refund' => [
                'kind' => 'refund',
                'income_source' => null,
                'transfer_purpose' => null,
            ],
            'income' => [
                'kind' => 'income',
                'income_source' => 'other',
                'transfer_purpose' => null,
            ],
            'savings' => [
                'kind' => 'transfer',
                'income_source' => null,
                'transfer_purpose' => 'savings',
            ],
            'card_payment' => [
                'kind' => 'transfer',
                'income_source' => null,
                'transfer_purpose' => 'card_payment',
            ],
            'transfer' => [
                'kind' => 'transfer',
                'income_source' => null,
                'transfer_purpose' => 'internal',
            ],
            default => null,
        };
    }
};

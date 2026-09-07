<?php

namespace App\Actions\StatementImports;

use App\Models\StatementImport;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

final class ReadStatementImports
{
    /**
     * @return LengthAwarePaginator<int, covariant array{
     *     id: int,
     *     financial_statement_format: string,
     *     period_start: string,
     *     period_end: string,
     *     instrument_label: string,
     *     instrument_last_four: string|null,
     *     movement_count: int,
     *     confirmed_at: string,
     *     linked_movement_count: int,
     *     created_movement_count: int,
     *     excluded_movement_count: int,
     *     totals: array<string, string>
     * }>
     */
    public function handle(User $owner): LengthAwarePaginator
    {
        return StatementImport::query()
            ->whereBelongsTo($owner, 'owner')
            ->select([
                'id',
                'user_id',
                'financial_statement_format',
                'period_start',
                'period_end',
                'instrument_label',
                'instrument_last_four',
                'reconciliation_values',
                'excluded_values',
                'confirmed_at',
            ])
            ->withCount('movements')
            ->withCount([
                'movements as linked_movement_count' => fn ($query) => $query->where('resolution', 'linked'),
                'movements as created_movement_count' => fn ($query) => $query->where('resolution', 'created'),
            ])
            ->latest('confirmed_at')
            ->latest('id')
            ->paginate(25)
            ->through(fn (StatementImport $import): array => [
                'id' => $import->id,
                'financial_statement_format' => $import->financial_statement_format->value,
                'period_start' => $import->period_start->toDateString(),
                'period_end' => $import->period_end->toDateString(),
                'instrument_label' => $import->instrument_label,
                'instrument_last_four' => $import->instrument_last_four,
                'movement_count' => $import->movements_count,
                'confirmed_at' => $import->confirmed_at->toIso8601String(),
                'linked_movement_count' => $import->linked_movement_count,
                'created_movement_count' => $import->created_movement_count,
                'excluded_movement_count' => count($import->excluded_values),
                'totals' => $import->reconciliation_values,
            ]);
    }
}

<?php

namespace App\Actions\StatementImports;

use App\Models\StatementImport;
use App\Models\User;
use App\StatementImports\StatementImportListItem;
use Illuminate\Pagination\LengthAwarePaginator;

final class ReadStatementImports
{
    /** @return LengthAwarePaginator<int, StatementImportListItem> */
    public function handle(User $owner): LengthAwarePaginator
    {
        $imports = StatementImport::query()
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
            ->paginate(25);
        $items = $imports->getCollection()
            ->map(fn (StatementImport $import): StatementImportListItem => new StatementImportListItem(
                id: $import->id,
                financialStatementFormat: $import->financial_statement_format->value,
                periodStart: $import->period_start->toDateString(),
                periodEnd: $import->period_end->toDateString(),
                instrumentLabel: $import->instrument_label,
                instrumentLastFour: $import->instrument_last_four,
                movementCount: $import->movements_count,
                confirmedAt: $import->confirmed_at->toIso8601String(),
                linkedMovementCount: $import->linked_movement_count,
                createdMovementCount: $import->created_movement_count,
                excludedMovementCount: count($import->excluded_values),
                totals: $import->reconciliation_values,
            ));

        $result = new LengthAwarePaginator(
            $items,
            $imports->total(),
            $imports->perPage(),
            $imports->currentPage(),
            $imports->getOptions(),
        );

        return $result;
    }
}

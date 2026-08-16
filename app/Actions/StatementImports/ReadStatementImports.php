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
            ->latest('confirmed_at')
            ->latest('id')
            ->paginate(25);
        $items = $imports->getCollection()
            ->map(fn (StatementImport $import): StatementImportListItem => new StatementImportListItem(
                id: $import->id,
                provider: $import->provider->value,
                periodStart: $import->period_start->toDateString(),
                periodEnd: $import->period_end->toDateString(),
                instrumentLabel: $import->instrument_label,
                instrumentLastFour: $import->instrument_last_four,
                movementCount: $import->movement_count,
                confirmedAt: $import->confirmed_at->toIso8601String(),
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

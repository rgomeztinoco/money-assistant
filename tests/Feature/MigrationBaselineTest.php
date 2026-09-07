<?php

use App\Models\StatementImport;
use App\Models\StatementMovement;
use Illuminate\Support\Facades\Schema;

test('Statement Import constraints protect replay source identity and Transaction linkage', function (): void {
    $importIndexes = collect(Schema::getIndexes('statement_imports'))->keyBy('name');
    $movementIndexes = collect(Schema::getIndexes('statement_movements'))->keyBy('name');
    $movementForeignKeys = collect(Schema::getForeignKeys('statement_movements'))->keyBy('name');
    $transactionIdColumn = collect(Schema::getColumns('statement_movements'))
        ->firstWhere('name', 'transaction_id');

    expect($importIndexes['statement_imports_user_id_file_hash_unique'])
        ->toMatchArray([
            'columns' => ['user_id', 'file_hash'],
            'unique' => true,
        ])
        ->and($movementIndexes['statement_movements_statement_import_id_source_row_id_unique'])
        ->toMatchArray([
            'columns' => ['statement_import_id', 'source_row_id'],
            'unique' => true,
        ])
        ->and($movementIndexes['statement_movements_statement_import_id_position_unique'])
        ->toMatchArray([
            'columns' => ['statement_import_id', 'position'],
            'unique' => true,
        ])
        ->and($movementIndexes['statement_movements_transaction_id_unique'])
        ->toMatchArray([
            'columns' => ['transaction_id'],
            'unique' => true,
        ])
        ->and($movementForeignKeys['statement_movements_transaction_id_foreign'])
        ->toMatchArray([
            'foreign_table' => 'transactions',
            'on_delete' => 'restrict',
        ])
        ->and($transactionIdColumn['nullable'])->toBeFalse();
});

test('Statement Import models mirror JSON column defaults', function (): void {
    expect(new StatementImport)
        ->excluded_values->toBe([])
        ->and(new StatementMovement)
        ->source_metadata->toBe([])
        ->match_evidence->toBe([]);
});

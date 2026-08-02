<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

#[Signature('app:financial-state:fingerprint')]
#[Description('Print a credential-free digest of the Owner Account financial state')]
class FingerprintFinancialState extends Command
{
    /** @throws JsonException */
    public function handle(): int
    {
        $digest = hash_init('sha256');

        foreach ($this->financialTables() as $table) {
            hash_update($digest, $table."\n");

            foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
                hash_update(
                    $digest,
                    json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n",
                );
            }
        }

        $this->line(hash_final($digest));

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function financialTables(): array
    {
        return [
            'transactions',
            'transaction_corrections',
            'transaction_state_changes',
            'categories',
            'category_assignments',
            'receipt_breakdowns',
            'line_items',
            'learned_rules',
            'learned_rule_revisions',
            'learned_rule_bulk_actions',
            'learned_rule_bulk_action_items',
            'daily_exchange_rates',
            'category_targets',
            'category_target_revisions',
            'suspected_duplicates',
            'suspected_duplicate_resolutions',
            'suspected_duplicate_source_moves',
            'suspected_duplicate_receipt_breakdown_moves',
            'financial_data_tombstones',
        ];
    }
}

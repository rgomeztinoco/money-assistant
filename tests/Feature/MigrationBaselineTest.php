<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('fresh PostgreSQL migrations contain only the lean v1 baseline', function (): void {
    $expectedTables = [
        'cache',
        'cache_locks',
        'categories',
        'deployment_rehearsal_probes',
        'failed_jobs',
        'gmail_connections',
        'gmail_message_discoveries',
        'job_batches',
        'jobs',
        'line_items',
        'merchant_rules',
        'migrations',
        'parser_profiles',
        'passkeys',
        'receipt_breakdowns',
        'runtime_health_checks',
        'sessions',
        'spending_notification_formats',
        'spending_notification_references',
        'transactions',
        'users',
    ];

    $actualTables = DB::table('information_schema.tables')
        ->where('table_schema', 'public')
        ->where('table_type', 'BASE TABLE')
        ->orderBy('table_name')
        ->pluck('table_name')
        ->all();

    expect($actualTables)->toBe($expectedTables)
        ->and(DB::table('migrations')->pluck('migration')->all())->toBe([
            '2026_08_13_150805_create_authentication_baseline',
            '2026_08_13_150806_create_cache_baseline',
            '2026_08_13_150807_create_queue_and_runtime_baseline',
            '2026_08_13_150808_create_ledger_baseline',
            '2026_08_13_150809_create_parser_profile_baseline',
            '2026_08_13_150810_create_gmail_baseline',
        ]);
});

test('baseline tables expose the retained application columns', function (string $table, array $columns): void {
    expect(Schema::getColumnListing($table))->toBe($columns);
})->with([
    'Owner Account' => ['users', [
        'id', 'name', 'email', 'password', 'remember_token', 'created_at', 'updated_at',
    ]],
    'Transactions' => ['transactions', [
        'id', 'user_id', 'occurred_on', 'amount_minor', 'currency', 'kind',
        'merchant_description', 'payment_instrument_label', 'payment_instrument_last_four',
        'confirmed_at', 'provisional_fields', 'voided_at', 'original_purchase_id',
        'refund_relationship_review_reasons', 'category_id', 'category_assignment_provenance',
        'merchant_rule_id', 'deployment_rehearsal_id', 'created_at', 'updated_at',
    ]],
    'Categories' => ['categories', [
        'id', 'user_id', 'parent_id', 'name', 'archived_at', 'created_at', 'updated_at',
    ]],
    'Merchant Rules' => ['merchant_rules', [
        'id', 'user_id', 'category_id', 'merchant', 'merchant_key', 'transaction_kind',
        'currency', 'enabled', 'created_at', 'updated_at', 'deleted_at',
    ]],
    'Receipt Breakdowns' => ['receipt_breakdowns', [
        'id', 'user_id', 'transaction_id', 'created_at', 'updated_at',
    ]],
    'Line Items' => ['line_items', [
        'id', 'line_item_id', 'receipt_breakdown_id', 'category_id', 'description',
        'quantity', 'unit_price_minor', 'line_total_minor', 'created_at', 'updated_at',
    ]],
    'Parser Profiles' => ['parser_profiles', [
        'id', 'user_id', 'name', 'trusted_sender_address', 'trusted_sender_domain',
        'authentication_mechanism', 'authenticated_domain', 'enabled_at', 'created_at', 'updated_at',
    ]],
    'Spending Notification Formats' => ['spending_notification_formats', [
        'id', 'parser_profile_id', 'name', 'mime_source', 'rule_identifier', 'purpose',
        'definition', 'enabled_at', 'created_at', 'updated_at',
    ]],
    'Gmail Connections' => ['gmail_connections', [
        'id', 'user_id', 'gmail_account_identity', 'access_token', 'refresh_token',
        'access_token_expires_at', 'granted_scopes', 'connected_at', 'last_successful_check_at',
        'last_check_failed_at', 'reauthorization_required_at', 'last_error_code', 'history_id',
        'initial_sync_completed_at', 'last_successful_sync_at', 'last_synchronization_failed_at',
        'last_synchronization_error_code', 'created_at', 'updated_at',
    ]],
    'Gmail Message Discoveries' => ['gmail_message_discoveries', [
        'id', 'gmail_connection_id', 'message_id', 'processed_at', 'processing_failed_at',
        'last_error_code', 'failed_job_uuid', 'created_at', 'updated_at',
    ]],
    'Spending Notification References' => ['spending_notification_references', [
        'id', 'user_id', 'transaction_id', 'spending_notification_format_id',
        'gmail_message_discovery_id', 'gmail_account_identity', 'message_id', 'processing_outcome',
        'attempt_count', 'last_attempted_at', 'created_at', 'updated_at',
    ]],
]);

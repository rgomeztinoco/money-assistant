<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS categories_enforce_hierarchy ON categories');
        DB::statement('DROP FUNCTION IF EXISTS enforce_category_hierarchy()');

        DB::statement('DROP INDEX IF EXISTS categories_active_root_name_unique');
        DB::statement('DROP INDEX IF EXISTS categories_active_child_name_unique');
        DB::statement('CREATE UNIQUE INDEX categories_active_root_name_unique ON categories (lower(name)) WHERE parent_id IS NULL AND archived_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX categories_active_child_name_unique ON categories (parent_id, lower(name)) WHERE parent_id IS NOT NULL AND archived_at IS NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_category_hierarchy() RETURNS trigger AS $$
            DECLARE
                grandparent_id bigint;
            BEGIN
                IF NEW.parent_id IS NOT NULL THEN
                    SELECT parent_id
                    INTO grandparent_id
                    FROM categories
                    WHERE id = NEW.parent_id;

                    IF grandparent_id IS NOT NULL THEN
                        RAISE EXCEPTION 'Categories support at most two levels.'
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM categories AS child
                    WHERE child.parent_id = NEW.id
                        AND NEW.parent_id IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'A parent Category must remain a root.'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER categories_enforce_hierarchy
            BEFORE INSERT OR UPDATE OF parent_id ON categories
            FOR EACH ROW EXECUTE FUNCTION enforce_category_hierarchy()
            SQL);

        DB::statement('DROP INDEX IF EXISTS merchant_rules_scope_unique');
        DB::statement('CREATE UNIQUE INDEX merchant_rules_scope_unique ON merchant_rules (merchant_key, transaction_kind, currency) NULLS NOT DISTINCT');

        DB::statement('ALTER TABLE spending_notification_references DROP CONSTRAINT spending_notification_references_source_identity_unique');
        DB::statement('CREATE UNIQUE INDEX spending_notification_references_source_identity_unique ON spending_notification_references (gmail_account_identity, message_id)');

        DB::statement('DROP INDEX IF EXISTS parser_profiles_owner_name_unique');
        DB::statement('CREATE UNIQUE INDEX parser_profiles_name_unique ON parser_profiles (lower(name))');

        DB::statement('CREATE UNIQUE INDEX gmail_connections_singleton ON gmail_connections ((true))');

        foreach ([
            'transactions',
            'categories',
            'merchant_rules',
            'parser_profiles',
            'gmail_connections',
            'spending_notification_references',
            'receipt_breakdowns',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('user_id');
            });
        }

        DB::statement('CREATE INDEX transactions_active_ledger_index ON transactions (occurred_on DESC, id DESC) WHERE voided_at IS NULL');
        DB::statement('CREATE INDEX transactions_voided_ledger_index ON transactions (voided_at DESC, id DESC) WHERE voided_at IS NOT NULL');
        DB::statement('CREATE INDEX transactions_review_queue_index ON transactions (occurred_on DESC, id DESC) WHERE jsonb_array_length(provisional_fields) > 0 AND voided_at IS NULL');
        DB::statement('CREATE INDEX transactions_refund_relationship_review_index ON transactions (occurred_on DESC, id DESC) WHERE jsonb_array_length(refund_relationship_review_reasons) > 0 AND voided_at IS NULL');
    }

    public function down(): void
    {
        throw new LogicException('Redundant ownership columns cannot be restored without reintroducing multi-owner semantics.');
    }
};

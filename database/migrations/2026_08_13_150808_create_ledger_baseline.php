<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'parent_id']);
            $table->index(['user_id', 'archived_at'], 'categories_user_id_retired_at_index');
        });

        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_name_not_blank CHECK (btrim(name) <> '')");
        DB::statement('CREATE UNIQUE INDEX categories_active_root_name_unique ON categories (user_id, lower(name)) WHERE parent_id IS NULL AND archived_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX categories_active_child_name_unique ON categories (user_id, parent_id, lower(name)) WHERE parent_id IS NOT NULL AND archived_at IS NULL');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_category_hierarchy() RETURNS trigger AS $$
            DECLARE
                parent_owner_id bigint;
                grandparent_id bigint;
            BEGIN
                IF NEW.parent_id IS NOT NULL THEN
                    SELECT user_id, parent_id
                    INTO parent_owner_id, grandparent_id
                    FROM categories
                    WHERE id = NEW.parent_id;

                    IF parent_owner_id IS DISTINCT FROM NEW.user_id THEN
                        RAISE EXCEPTION 'A Category and its parent must have the same owner.'
                            USING ERRCODE = '23514';
                    END IF;

                    IF grandparent_id IS NOT NULL THEN
                        RAISE EXCEPTION 'Categories support at most two levels.'
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM categories AS child
                    WHERE child.parent_id = NEW.id
                        AND (
                            NEW.parent_id IS NOT NULL
                            OR child.user_id IS DISTINCT FROM NEW.user_id
                        )
                ) THEN
                    RAISE EXCEPTION 'A parent Category must remain a root with the same owner as its children.'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER categories_enforce_hierarchy
            BEFORE INSERT OR UPDATE OF user_id, parent_id ON categories
            FOR EACH ROW EXECUTE FUNCTION enforce_category_hierarchy()
            SQL);

        Schema::create('merchant_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('merchant');
            $table->string('merchant_key');
            $table->string('transaction_kind', 16)->nullable();
            $table->string('currency', 3)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->softDeletesTz();

            $table->index(['user_id', 'merchant_key', 'enabled']);
        });

        DB::statement("ALTER TABLE merchant_rules ADD CONSTRAINT merchant_rules_kind_supported CHECK (transaction_kind IS NULL OR transaction_kind IN ('purchase', 'refund'))");
        DB::statement("ALTER TABLE merchant_rules ADD CONSTRAINT merchant_rules_currency_supported CHECK (currency IS NULL OR currency IN ('USD', 'PEN'))");
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX merchant_rules_scope_unique
            ON merchant_rules (user_id, merchant_key, transaction_kind, currency) NULLS NOT DISTINCT
            WHERE deleted_at IS NULL
            SQL);

        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('occurred_on');
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('kind', 16);
            $table->string('merchant_description');
            $table->string('payment_instrument_label', 100)->nullable();
            $table->string('payment_instrument_last_four', 4)->nullable();
            $table->timestamp('confirmed_at');
            $table->jsonb('provisional_fields')->default('[]');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('original_purchase_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->jsonb('refund_relationship_review_reasons')->default('[]');
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('category_assignment_provenance', 32)->nullable();
            $table->foreignId('merchant_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('deployment_rehearsal_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['user_id', 'occurred_on', 'id']);
        });

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_amount_minor_positive CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_currency_supported CHECK (currency IN ('USD', 'PEN'))");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_kind_supported CHECK (kind IN ('purchase', 'refund'))");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_payment_instrument_last_four_valid CHECK (payment_instrument_last_four IS NULL OR payment_instrument_last_four ~ '^[0-9]{4}$')");
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_original_purchase_not_self CHECK (original_purchase_id IS NULL OR original_purchase_id <> id)');
        DB::statement(<<<'SQL'
            ALTER TABLE transactions
            ADD CONSTRAINT transactions_provisional_fields_reviewable
            CHECK (
                jsonb_typeof(provisional_fields) = 'array'
                AND provisional_fields <@ '["occurred_on", "amount_minor", "currency", "kind", "merchant_description"]'::jsonb
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE transactions
            ADD CONSTRAINT transactions_refund_relationship_review_reasons_supported
            CHECK (
                jsonb_typeof(refund_relationship_review_reasons) = 'array'
                AND refund_relationship_review_reasons <@ '[
                    "cumulative_refunds_exceed_purchase",
                    "receipt_breakdown_allocation_requires_review"
                ]'::jsonb
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE transactions
            ADD CONSTRAINT transactions_category_assignment_complete CHECK (
                (category_id IS NULL AND category_assignment_provenance IS NULL AND merchant_rule_id IS NULL)
                OR (
                    category_id IS NOT NULL
                    AND (
                        (category_assignment_provenance = 'merchant_rule' AND merchant_rule_id IS NOT NULL)
                        OR (category_assignment_provenance = 'owner' AND merchant_rule_id IS NULL)
                    )
                )
            )
            SQL);
        DB::statement('CREATE INDEX transactions_active_ledger_index ON transactions (user_id, occurred_on DESC, id DESC) WHERE voided_at IS NULL');
        DB::statement('CREATE INDEX transactions_voided_ledger_index ON transactions (user_id, voided_at DESC, id DESC) WHERE voided_at IS NOT NULL');
        DB::statement('CREATE INDEX transactions_review_queue_index ON transactions (user_id, occurred_on DESC, id DESC) WHERE jsonb_array_length(provisional_fields) > 0 AND voided_at IS NULL');
        DB::statement('CREATE INDEX transactions_refund_relationship_review_index ON transactions (user_id, occurred_on DESC, id DESC) WHERE jsonb_array_length(refund_relationship_review_reasons) > 0 AND voided_at IS NULL');

        Schema::create('receipt_breakdowns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('line_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('line_item_id')->unique();
            $table->foreignId('receipt_breakdown_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->string('quantity', 64)->nullable();
            $table->bigInteger('unit_price_minor')->nullable();
            $table->bigInteger('line_total_minor');
            $table->timestamps();

            $table->index(['receipt_breakdown_id', 'id']);
            $table->index('category_id');
        });

        DB::statement('ALTER TABLE line_items ADD CONSTRAINT line_items_total_nonzero CHECK (line_total_minor <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('line_items');
        Schema::dropIfExists('receipt_breakdowns');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('merchant_rules');
        DB::statement('DROP TRIGGER IF EXISTS categories_enforce_hierarchy ON categories');
        Schema::dropIfExists('categories');
        DB::statement('DROP FUNCTION IF EXISTS enforce_category_hierarchy()');
    }
};

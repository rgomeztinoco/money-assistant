<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            $table->unique(
                ['user_id', 'parent_id', 'name', 'archived_at'],
                'categories_sibling_name_state_unique',
            )->nullsNotDistinct();
        });

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
            $table->unique(
                ['user_id', 'merchant_key', 'transaction_kind', 'currency', 'deleted_at'],
                'merchant_rules_scope_state_unique',
            )->nullsNotDistinct();
        });

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
            $table->index(
                ['user_id', 'voided_at', 'occurred_on', 'id'],
                'transactions_ledger_state_index',
            );
        });

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
    }

    public function down(): void
    {
        Schema::dropIfExists('line_items');
        Schema::dropIfExists('receipt_breakdowns');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('merchant_rules');
        Schema::dropIfExists('categories');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('statement_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('parser_version', 32);
            $table->char('file_hash', 64);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('instrument_label', 100);
            $table->string('instrument_last_four', 4)->nullable();
            $table->jsonb('reconciliation_values');
            $table->unsignedSmallInteger('movement_count');
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->unique(['user_id', 'file_hash']);
            $table->index(['user_id', 'confirmed_at', 'id']);
        });

        Schema::create('statement_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('statement_import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->char('source_row_id', 64);
            $table->unsignedSmallInteger('position');
            $table->date('occurred_on');
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('direction', 8);
            $table->string('classification', 32);
            $table->string('description');
            $table->string('instrument_label', 100);
            $table->string('instrument_last_four', 4)->nullable();
            $table->jsonb('source_metadata')->default('{}');
            $table->timestamps();

            $table->unique(['statement_import_id', 'source_row_id']);
            $table->unique(['statement_import_id', 'position']);
            $table->index(['statement_import_id', 'occurred_on', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('statement_movements');
        Schema::dropIfExists('statement_imports');
    }
};

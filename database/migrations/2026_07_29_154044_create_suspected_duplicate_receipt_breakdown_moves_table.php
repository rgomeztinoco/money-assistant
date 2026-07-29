<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('suspected_duplicate_receipt_breakdown_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suspected_duplicate_resolution_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedBigInteger('receipt_breakdown_id');
            $table->foreignId('from_transaction_id')
                ->constrained('transactions')
                ->restrictOnDelete();
            $table->foreignId('to_transaction_id')
                ->constrained('transactions')
                ->restrictOnDelete();
            $table->unsignedInteger('receipt_breakdown_revision');
            $table->string('receipt_breakdown_status', 16);
            $table->timestamps();

            $table->unique([
                'suspected_duplicate_resolution_id',
                'receipt_breakdown_id',
            ], 'suspected_duplicate_breakdown_moves_resolution_breakdown_unique');
            $table->index('receipt_breakdown_id');
        });

        DB::statement('ALTER TABLE suspected_duplicate_receipt_breakdown_moves ADD CONSTRAINT suspected_duplicate_breakdown_moves_distinct_transactions CHECK (from_transaction_id <> to_transaction_id)');
        DB::statement('ALTER TABLE suspected_duplicate_receipt_breakdown_moves ADD CONSTRAINT suspected_duplicate_breakdown_moves_revision_positive CHECK (receipt_breakdown_revision > 0)');
        DB::statement("ALTER TABLE suspected_duplicate_receipt_breakdown_moves ADD CONSTRAINT suspected_duplicate_breakdown_moves_status_supported CHECK (receipt_breakdown_status IN ('draft', 'confirmed', 'superseded'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suspected_duplicate_receipt_breakdown_moves');
    }
};

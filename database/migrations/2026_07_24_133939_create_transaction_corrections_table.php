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
        Schema::create('transaction_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('field', 32);
            $table->string('previous_value');
            $table->string('corrected_value');
            $table->unsignedInteger('transaction_revision');
            $table->timestamps();

            $table->unique(['transaction_id', 'transaction_revision']);
        });

        DB::statement("ALTER TABLE transaction_corrections ADD CONSTRAINT transaction_corrections_field_reviewable CHECK (field IN ('occurred_on', 'amount_minor', 'currency', 'kind', 'merchant_description'))");
        DB::statement('ALTER TABLE transaction_corrections ADD CONSTRAINT transaction_corrections_revision_positive CHECK (transaction_revision > 1)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_corrections');
    }
};

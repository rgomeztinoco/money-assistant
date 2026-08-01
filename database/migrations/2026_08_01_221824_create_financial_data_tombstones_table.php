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
        Schema::create('financial_data_tombstones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('owner_id');
            $table->string('resource_type', 64);
            $table->unsignedBigInteger('resource_id');
            $table->string('source_reference_type', 64)->nullable();
            $table->unsignedBigInteger('source_reference_id')->nullable();
            $table->timestampTz('deleted_at');
            $table->timestampTz('purged_at');

            $table->index(['owner_id', 'resource_type', 'resource_id']);
            $table->unique(['source_reference_type', 'source_reference_id']);
        });

        DB::statement("ALTER TABLE financial_data_tombstones ADD CONSTRAINT financial_data_tombstones_resource_type_supported CHECK (resource_type IN ('category', 'receipt_breakdown'))");
        DB::statement("ALTER TABLE financial_data_tombstones ADD CONSTRAINT financial_data_tombstones_source_reference_supported CHECK ((source_reference_type IS NULL AND source_reference_id IS NULL) OR (source_reference_type = 'receipt_proposal' AND source_reference_id IS NOT NULL))");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_financial_data_tombstone_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Financial data tombstones are append-only.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER financial_data_tombstones_append_only
            BEFORE UPDATE OR DELETE ON financial_data_tombstones
            FOR EACH ROW EXECUTE FUNCTION reject_financial_data_tombstone_mutation();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS financial_data_tombstones_append_only ON financial_data_tombstones');
        DB::statement('DROP FUNCTION IF EXISTS reject_financial_data_tombstone_mutation()');
        Schema::dropIfExists('financial_data_tombstones');
    }
};

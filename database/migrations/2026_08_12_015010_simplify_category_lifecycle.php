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
        DB::statement('DROP INDEX categories_active_root_name_unique');
        DB::statement('DROP INDEX categories_active_child_name_unique');

        Schema::table('categories', function (Blueprint $table) {
            $table->renameColumn('retired_at', 'archived_at');
        });

        DB::statement(<<<'SQL'
            UPDATE categories
            SET archived_at = COALESCE(archived_at, deleted_at)
            WHERE deleted_at IS NOT NULL
            SQL);
        DB::statement('ALTER TABLE categories DROP CONSTRAINT categories_revision_positive');

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['revision', 'deletion_id', 'purge_after', 'deleted_at']);
        });

        DB::statement('CREATE UNIQUE INDEX categories_active_root_name_unique ON categories (user_id, lower(name)) WHERE parent_id IS NULL AND archived_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX categories_active_child_name_unique ON categories (user_id, parent_id, lower(name)) WHERE parent_id IS NOT NULL AND archived_at IS NULL');

        DB::statement('DROP TRIGGER IF EXISTS financial_data_tombstones_append_only ON financial_data_tombstones');
        Schema::dropIfExists('financial_data_tombstones');
        DB::statement('DROP FUNCTION IF EXISTS reject_financial_data_tombstone_mutation()');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Removed Category revisions and financial trash cannot be restored.');
    }
};

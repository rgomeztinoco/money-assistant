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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('categories')
                ->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->jsonb('examples')->default('[]');
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'parent_id']);
            $table->index(['user_id', 'retired_at']);
        });

        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_name_not_blank CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_examples_array CHECK (jsonb_typeof(examples) = 'array')");
        DB::statement('CREATE UNIQUE INDEX categories_active_root_name_unique ON categories (user_id, lower(name)) WHERE parent_id IS NULL AND retired_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX categories_active_child_name_unique ON categories (user_id, parent_id, lower(name)) WHERE parent_id IS NOT NULL AND retired_at IS NULL');
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS categories_enforce_hierarchy ON categories');
        DB::statement('DROP FUNCTION IF EXISTS enforce_category_hierarchy()');
        Schema::dropIfExists('categories');
    }
};

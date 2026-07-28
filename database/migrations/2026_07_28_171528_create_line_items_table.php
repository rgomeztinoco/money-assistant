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
        Schema::create('line_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('line_item_id')->unique();
            $table->foreignId('receipt_breakdown_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->string('role', 32)->default('purchased_item');
            $table->bigInteger('line_total_minor');
            $table->boolean('requires_review')->default(false);
            $table->timestamps();

            $table->index(['receipt_breakdown_id', 'id']);
            $table->index('category_id');
        });

        DB::statement("ALTER TABLE line_items ADD CONSTRAINT line_items_role_supported CHECK (role = 'purchased_item')");
        DB::statement('ALTER TABLE line_items ADD CONSTRAINT line_items_purchased_total_positive CHECK (line_total_minor > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('line_items');
    }
};

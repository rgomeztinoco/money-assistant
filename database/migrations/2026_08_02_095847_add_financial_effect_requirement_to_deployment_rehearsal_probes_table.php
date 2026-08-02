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
        Schema::table('deployment_rehearsal_probes', function (Blueprint $table) {
            $table->boolean('requires_financial_effect')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deployment_rehearsal_probes', function (Blueprint $table) {
            $table->dropColumn('requires_financial_effect');
        });
    }
};

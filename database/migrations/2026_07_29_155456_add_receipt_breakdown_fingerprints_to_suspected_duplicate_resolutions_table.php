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
        Schema::table('suspected_duplicate_resolutions', function (Blueprint $table) {
            $table->string('expected_first_receipt_breakdown_fingerprint', 64)
                ->nullable();
            $table->string('expected_second_receipt_breakdown_fingerprint', 64)
                ->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suspected_duplicate_resolutions', function (Blueprint $table) {
            $table->dropColumn([
                'expected_first_receipt_breakdown_fingerprint',
                'expected_second_receipt_breakdown_fingerprint',
            ]);
        });
    }
};

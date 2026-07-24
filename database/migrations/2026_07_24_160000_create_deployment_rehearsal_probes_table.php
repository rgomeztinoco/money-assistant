<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_rehearsal_probes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('rehearsal_id')->index();
            $table->enum('kind', ['queued', 'scheduled']);
            $table->timestampTz('due_at');
            $table->timestampTz('completed_at')->nullable();
            $table->unsignedTinyInteger('completion_count')->default(0);
            $table->timestampsTz();

            $table->unique(['rehearsal_id', 'kind']);
            $table->index(['kind', 'completed_at', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_rehearsal_probes');
    }
};

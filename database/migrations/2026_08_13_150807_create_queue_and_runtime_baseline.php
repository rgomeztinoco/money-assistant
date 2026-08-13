<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();

            $table->index(['connection', 'queue', 'failed_at']);
        });

        Schema::create('runtime_health_checks', function (Blueprint $table): void {
            $table->string('service')->primary();
            $table->timestampTz('last_seen_at');
        });

        Schema::create('deployment_rehearsal_probes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('rehearsal_id')->index();
            $table->enum('kind', ['queued', 'scheduled']);
            $table->timestampTz('due_at');
            $table->timestampTz('completed_at')->nullable();
            $table->unsignedTinyInteger('completion_count')->default(0);
            $table->timestampsTz();
            $table->boolean('requires_financial_effect')->default(false);

            $table->unique(['rehearsal_id', 'kind']);
            $table->index(['kind', 'completed_at', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_rehearsal_probes');
        Schema::dropIfExists('runtime_health_checks');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
    }
};

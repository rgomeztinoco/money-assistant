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
        Schema::create('gmail_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('gmail_account_identity');
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestampTz('access_token_expires_at');
            $table->jsonb('granted_scopes');
            $table->timestampTz('connected_at');
            $table->timestampTz('last_successful_check_at');
            $table->timestampTz('last_check_failed_at')->nullable();
            $table->timestampTz('reauthorization_required_at')->nullable()->index();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gmail_connections');
    }
};

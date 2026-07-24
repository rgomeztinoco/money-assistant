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
        Schema::create('open_claw_request_nonces', function (Blueprint $table) {
            $table->id();
            $table->string('key_id', 128);
            $table->char('nonce_digest', 64);
            $table->timestamp('expires_at');
            $table->timestamp('created_at');

            $table->unique(['key_id', 'nonce_digest']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('open_claw_request_nonces');
    }
};

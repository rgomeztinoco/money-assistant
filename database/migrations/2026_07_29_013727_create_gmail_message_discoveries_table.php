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
        Schema::create('gmail_message_discoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gmail_connection_id')->constrained()->cascadeOnDelete();
            $table->string('message_id');
            $table->timestampTz('processed_at')->nullable()->index();
            $table->timestamps();

            $table->unique([
                'gmail_connection_id',
                'message_id',
            ], 'gmail_message_discoveries_identity_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gmail_message_discoveries');
    }
};

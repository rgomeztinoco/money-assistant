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
        Schema::create('spending_notification_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('gmail_account_identity');
            $table->string('message_id');
            $table->string('processing_outcome', 32);
            $table->timestamps();

            $table->unique([
                'user_id',
                'gmail_account_identity',
                'message_id',
            ], 'spending_notification_references_source_identity_unique');
            $table->index('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spending_notification_references');
    }
};

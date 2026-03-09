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
        Schema::create('pairing_entries', function (Blueprint $table) {
            $table->id();
            $table->string('channel');
            $table->string('sender_id');
            $table->string('sender');
            $table->string('code')->nullable();
            $table->enum('status', ['pending', 'approved'])->default('pending');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('approved_code')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'sender_id']);
            $table->index('code');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pairing_entries');
    }
};

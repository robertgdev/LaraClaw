<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Conversations are also Sessions - each conversation IS a session.
     * - conversation_id: UUID identifier (also serves as session_id)
     * - label: User-assigned name (via "rename session to X")
     * - derived_title: Auto-generated from first message
     * - is_active: Currently active session for this sender_id+channel
     * - is_pinned: Pinned sessions appear at top
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('conversation_id')->unique();
            $table->string('channel'); // telegram, discord, whatsapp, cli, web
            $table->string('sender');
            $table->string('sender_id')->nullable();
            $table->string('team_id')->nullable()->constrained('teams', 'team_id')->nullOnDelete();
            $table->string('label')->nullable();           // User-assigned name
            $table->string('derived_title')->nullable();    // Auto from first message
            $table->boolean('is_active')->default(true);    // Active session for sender
            $table->boolean('is_pinned')->default(false);   // Pinned sessions

            $table->unsignedInteger('total_messages')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_message_at')->nullable(); // For sorting sessions
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for common queries
            $table->index('channel');
            $table->index('sender_id');
            $table->index('team_id');
            $table->index('created_at');
            $table->index('completed_at');
            $table->index('last_message_at');

            // Composite index for filtering by channel and date
            $table->index(['channel', 'created_at']);

            // Composite index for finding active session per user
            $table->index(['sender_id', 'channel', 'is_active']);

            // Index for listing sessions sorted by last message
            $table->index(['sender_id', 'channel', 'last_message_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};

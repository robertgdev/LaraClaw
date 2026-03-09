<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('memories', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // User identification (matches Conversation/ConversationMessage pattern)
            $table->string('sender_id')->index();
            $table->string('channel', 20)->index();

            // Optional agent context
            $table->string('agent_id')->nullable()->index();

            // Event data (string field, not enum - cast via model)
            $table->string('event_type', 50);
            $table->text('content');
            $table->text('outcome')->nullable();

            // Scoring fields
            $table->decimal('importance', 3, 2)->default(0.50);
            $table->unsignedInteger('access_count')->default(0);

            // Timestamps
            $table->timestamps();
            $table->timestamp('last_accessed_at')->useCurrent();

            // Composite indexes for common queries
            $table->index(['sender_id', 'channel']);
            $table->index(['sender_id', 'channel', 'created_at']);
            $table->index(['sender_id', 'channel', 'importance']);
            $table->index(['sender_id', 'channel', 'event_type']);
        });

        // Add FULLTEXT index for MySQL
        if (config('database.default') === 'mysql') {
            DB::statement('ALTER TABLE episodic_memory ADD FULLTEXT INDEX episodic_memory_fulltext (content, outcome)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('memories');
    }
};

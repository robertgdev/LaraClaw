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
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('message_id')->unique();
            $table->string('conversation_id')->constrained('conversations', 'conversation_id')->cascadeOnDelete();
            $table->foreignId('reply_to')->nullable()->constrained('conversation_messages', 'id')->nullOnDelete();
            $table->index('reply_to');
            $table->string('channel'); // telegram, discord, whatsapp, cli
            $table->string('direction')->default('incoming'); // incoming, outgoing
            $table->boolean('is_internal')->default(false);
            $table->string('sender');
            $table->string('sender_id')->nullable();
            $table->text('message');
            $table->boolean('is_llm')->default(false);
            $table->string('agent_id')->nullable()->constrained('agents', 'agent_id')->nullOnDelete();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('token_cost')->nullable();
            $table->unsignedInteger('token_cost_estimated')->nullable();
            $table->json('files')->nullable();
            $table->string('status')->default('pending');
            $table->string('queue_type')->default('incoming'); // incoming, outgoing, processing
            $table->integer('retry_count')->unsigned()->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'queue_type']);
            $table->index(['conversation_id', 'created_at']);
            $table->index('channel');
            $table->index('agent_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the lossless memory compaction tables:
     * - memory_summaries: Hierarchical summaries (leaf and condensed)
     * - memory_context_items: Ordered list of messages and summaries forming conversation context
     * - memory_summary_messages: Junction table linking leaf summaries to source messages
     * - memory_summary_parents: Junction table linking condensed summaries to parent summaries
     * - memory_large_files: Metadata for large file attachments
     */
    public function up(): void
    {
        // Memory summaries table - stores hierarchical summaries
        Schema::create('memory_summaries', function (Blueprint $table) {
            $table->string('summary_id', 64)->primary();
            $table->foreignId('conversation_id')->constrained('conversations', 'id')->onDelete('cascade');
            $table->enum('kind', ['leaf', 'condensed']);
            $table->unsignedInteger('depth')->default(0);
            $table->text('content');
            $table->unsignedInteger('token_count');
            $table->timestamp('earliest_at')->nullable();
            $table->timestamp('latest_at')->nullable();
            $table->unsignedInteger('descendant_count')->default(0);
            $table->unsignedInteger('descendant_token_count')->default(0);
            $table->unsignedInteger('source_message_token_count')->default(0);
            $table->json('file_ids')->default('[]');
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['conversation_id', 'depth']);
        });

        // Memory context items table - ordered list of messages and summaries
        Schema::create('memory_context_items', function (Blueprint $table) {
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('ordinal');
            $table->enum('item_type', ['message', 'summary']);
            $table->foreignId('message_id')->nullable()->constrained('conversation_messages', 'id')->onDelete('restrict');
            $table->string('summary_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['conversation_id', 'ordinal']);

            // Note: Application-level validation ensures message_id is set for 'message' type
            // and summary_id is set for 'summary' type
        });

        // Add foreign key for summary_id separately to avoid constraint issues
        Schema::table('memory_context_items', function (Blueprint $table) {
            $table->foreign('summary_id')->references('summary_id')->on('memory_summaries')->onDelete('restrict');
        });

        // Memory summary messages junction table - links leaf summaries to source messages
        Schema::create('memory_summary_messages', function (Blueprint $table) {
            $table->string('summary_id', 64);
            $table->foreignId('message_id')->constrained('conversation_messages', 'id')->onDelete('restrict');
            $table->unsignedInteger('ordinal');
            $table->primary(['summary_id', 'message_id']);

            $table->foreign('summary_id')->references('summary_id')->on('memory_summaries')->onDelete('cascade');
        });

        // Memory summary parents junction table - links condensed summaries to parent summaries
        Schema::create('memory_summary_parents', function (Blueprint $table) {
            $table->string('summary_id', 64);
            $table->string('parent_summary_id', 64);
            $table->unsignedInteger('ordinal');
            $table->primary(['summary_id', 'parent_summary_id']);

            $table->foreign('summary_id')->references('summary_id')->on('memory_summaries')->onDelete('cascade');
            $table->foreign('parent_summary_id')->references('summary_id')->on('memory_summaries')->onDelete('restrict');
        });

        // Memory large files table - stores metadata for large file attachments
        Schema::create('memory_large_files', function (Blueprint $table) {
            $table->string('file_id', 64)->primary();
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->string('file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('storage_uri');
            $table->text('exploration_summary')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};

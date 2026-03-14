<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds feedback fields to conversations and conversation_messages tables.
     * Feedback values: positive (thumbs up), negative (thumbs down), neutral.
     * Stored as: 1 = positive, -1 = negative, 0 = neutral/null = no feedback
     */
    public function up(): void
    {
        // Add feedback to conversations
        Schema::table('conversations', function (Blueprint $table) {
            $table->tinyInteger('feedback')->nullable()->after('completed_at')->comment('1=positive, -1=negative, 0=neutral');
            $table->text('feedback_comment')->nullable()->after('feedback');
            $table->timestamp('feedback_at')->nullable()->after('feedback_comment');
            $table->index('feedback');
        });

        // Add feedback to conversation_messages
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->tinyInteger('feedback')->nullable()->after('processed_at')->comment('1=positive, -1=negative, 0=neutral');
            $table->text('feedback_comment')->nullable()->after('feedback');
            $table->timestamp('feedback_at')->nullable()->after('feedback_comment');
            $table->index('feedback');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->dropColumn(['feedback', 'feedback_comment', 'feedback_at']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['feedback', 'feedback_comment', 'feedback_at']);
        });
    }
};

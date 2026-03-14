<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds feedback score to memories table.
     * This allows memories to be scored based on user feedback.
     */
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->decimal('feedback_score', 3, 2)->nullable()->after('importance')->comment('Feedback score from -1.00 to 1.00');
            $table->unsignedInteger('feedback_count')->default(0)->after('feedback_score')->comment('Number of feedback signals received');
            $table->index('feedback_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropColumn(['feedback_score', 'feedback_count']);
        });
    }
};

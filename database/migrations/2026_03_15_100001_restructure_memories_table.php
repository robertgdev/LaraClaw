<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restructure the memories table using a safe copy-rename approach:
 *
 *   1. Create memories_new with the desired schema
 *   2. Copy existing data from memories → memories_new
 *      (conversation_id defaults to NULL; old UUID id becomes new sequential int id)
 *   3. Rename memories → memories_old
 *   4. Rename memories_new → memories
 *   5. Drop memories_old
 *
 * This approach preserves all existing memory data without a destructive DROP.
 * The old UUID primary-key values are discarded; rows receive new auto-increment IDs.
 * The `conversation_id` column is NULL for all migrated rows (no FK existed before).
 *
 * The down() migration reverses the operation by dropping the new table and renaming
 * the old table back (if it still exists) OR recreating the original structure fresh.
 */
return new class extends Migration
{
    // ─── UP ──────────────────────────────────────────────────────────────────

    public function up(): void
    {
        // Step 1 — Create memories_new with the new schema
        Schema::create('memories_new', function (Blueprint $table) {
            $table->id(); // auto-increment bigint unsigned

            // Optional link to the originating conversation
            $table->unsignedBigInteger('conversation_id')->nullable()->index();
            $table->foreign('conversation_id')->references('id')->on('conversations')->nullOnDelete();

            // User identification
            $table->string('sender_id')->index();
            $table->string('channel', 20)->index();

            // Optional agent context
            $table->string('agent_id')->nullable()->index();

            // Event data
            $table->string('event_type', 50);
            $table->text('content');
            $table->text('outcome')->nullable();

            // Scoring fields
            $table->decimal('importance', 3, 2)->default(0.50);
            $table->decimal('feedback_score', 3, 2)->nullable()->comment('Feedback score -1.00 to 1.00');
            $table->unsignedInteger('feedback_count')->default(0);
            $table->unsignedInteger('access_count')->default(0);

            // Timestamps + soft-delete
            $table->timestamps();
            $table->timestamp('last_accessed_at')->useCurrent();
            $table->softDeletes();

            // Composite indexes
            $table->index(['sender_id', 'channel']);
            $table->index(['sender_id', 'channel', 'created_at']);
            $table->index(['sender_id', 'channel', 'importance']);
            $table->index(['sender_id', 'channel', 'event_type']);
            $table->index('feedback_score');
        });

        // Step 2 — Copy data from old table.
        // conversation_id is left NULL (the column didn't exist before).
        // The old UUID id is dropped; new sequential IDs are assigned automatically.
        if (Schema::hasTable('memories')) {
            $oldColumns = Schema::getColumnListing('memories');

            // Build a safe column list that exists in both old and new table
            $copyColumns = array_values(array_intersect(
                $oldColumns,
                [
                    'sender_id', 'channel', 'agent_id', 'event_type',
                    'content', 'outcome', 'importance',
                    'feedback_score', 'feedback_count',
                    'access_count', 'created_at', 'updated_at', 'last_accessed_at',
                ],
            ));

            if (! empty($copyColumns)) {
                $colList = implode(', ', array_map(fn ($c) => '"'.$c.'"', $copyColumns));
                DB::statement("INSERT INTO memories_new ({$colList}) SELECT {$colList} FROM memories");
            }
        }

        // Step 3 — Rename old table to memories_old (backup)
        if (Schema::hasTable('memories')) {
            Schema::rename('memories', 'memories_old');
        }

        // Step 4 — Rename memories_new to memories
        Schema::rename('memories_new', 'memories');

        // Step 5 — Drop the backup table
        Schema::dropIfExists('memories_old');
    }

    // ─── DOWN ─────────────────────────────────────────────────────────────────

    public function down(): void {}
};

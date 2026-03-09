<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates a table for tracking skills and their classification state.
     * Stores checksums to detect changes and avoid re-classifying unchanged skills.
     */
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();

            // Core skill info
            $table->string('name', 100)->unique();
            $table->string('dir_name', 100);
            $table->string('path', 500);
            $table->string('source_type', 50)->default('default');
            $table->text('description');
            $table->string('license', 50)->nullable();
            $table->json('keywords')->nullable();

            // Checksum for change detection (SHA-256 of all skill files)
            $table->string('checksum', 64)->index();

            // Feature flags
            $table->boolean('has_scripts')->default(false);
            $table->boolean('has_references')->default(false);
            $table->boolean('has_assets')->default(false);

            // Classification tracking
            $table->enum('classification_status', ['pending', 'classified', 'failed', 'skipped'])->default('pending');
            $table->timestamp('classified_at')->nullable();
            $table->string('classification_provider', 50)->nullable();
            $table->string('classification_model', 100)->nullable();
            $table->unsignedInteger('intents_count')->default(0);
            $table->text('last_error')->nullable();

            // Status
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes for common query patterns
            $table->index('source_type');
            $table->index('classification_status');
            $table->index('is_active');
            $table->index('classified_at');

            // Composite indexes for filtered queries
            $table->index(['is_active', 'classification_status']);
            $table->index(['source_type', 'is_active']);
            $table->index(['classification_status', 'classified_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};

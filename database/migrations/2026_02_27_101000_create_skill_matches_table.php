<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates a cache table for intent->skill mappings to reduce LLM calls
     * for skill matching. Stores normalized intent signatures and matched skills.
     *
     * Uses skill_id as a foreign key to the laraclaw_skills table for referential integrity.
     */
    public function up(): void
    {
        Schema::create('skill_matches', function (Blueprint $table) {
            $table->id();

            // Normalized intent signature (MD5 hash of sorted keywords)
            $table->string('intent_signature', 32)->unique();

            // Extracted keywords from the message (JSON array)
            $table->json('intent_keywords');

            // Foreign key to skills table (replaces matched_skill string)
            $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();

            // Confidence score (0.00 to 1.00)
            $table->decimal('confidence_score', 3, 2)->default(0.00);

            // Original message sample (for debugging/analysis)
            $table->text('sample_message')->nullable();

            // Usage tracking
            $table->unsignedInteger('hit_count')->default(1);

            // Intent category (from IntentClassificationService)
            $table->string('intent_category', 50)->nullable();

            // Extracted entities (JSON: locations, dates, etc.)
            $table->json('entities')->nullable();

            // Agent suggestion (if any)
            $table->string('suggested_agent', 100)->nullable();

            // Metadata for analytics
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes for common query patterns
            $table->index('intent_category');
            $table->index('confidence_score');
            $table->index('hit_count');
            $table->index('created_at');

            // Composite indexes for filtered queries
            $table->index(['skill_id', 'confidence_score']);
            $table->index(['intent_category', 'confidence_score']);
            $table->index(['hit_count', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('skill_match');
    }
};

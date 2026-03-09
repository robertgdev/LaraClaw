<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates a pivot table for the many-to-many relationship between agents and teams.
     * This allows for proper Eloquent relationships instead of JSON column storage.
     */
    public function up(): void
    {
        Schema::create('agent_team', function (Blueprint $table) {
            $table->id();
            $table->string('agent_id')->constrained('agents', 'agent_id')->cascadeOnDelete();
            $table->string('team_id')->constrained('teams', 'team_id')->cascadeOnDelete();
            $table->timestamps();

            // Unique constraint to prevent duplicate entries
            $table->unique(['agent_id', 'team_id']);

            // Indexes for faster lookups
            $table->index('agent_id');
            $table->index('team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_team');
    }
};

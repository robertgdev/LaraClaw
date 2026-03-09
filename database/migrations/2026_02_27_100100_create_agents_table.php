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
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('agent_id')->unique();
            $table->string('name');
            $table->string('provider')->default('anthropic');
            $table->string('model')->default('claude-sonnet-4-5');
            $table->string('working_directory')->nullable();
            $table->text('system_prompt')->nullable();
            $table->text('prompt_file')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('skills')->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('provider');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};

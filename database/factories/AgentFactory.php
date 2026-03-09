<?php

namespace Database\Factories;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Agent>
 */
class AgentFactory extends Factory
{
    protected $model = Agent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => fake()->unique()->slug(2),
            'name' => fake()->name(),
            'provider' => fake()->randomElement(['anthropic', 'openai', 'gemini', 'groq']),
            'model' => fake()->randomElement(['claude-sonnet-4-5', 'gpt-4o', 'gemini-2.0-flash', 'llama-3.3-70b-versatile']),
            'working_directory' => fake()->optional()->filePath(),
            'system_prompt' => fake()->optional()->paragraph(),
            'prompt_file' => fake()->optional()->filePath(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the agent is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Create an agent with Anthropic provider.
     */
    public function anthropic(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
        ]);
    }

    /**
     * Create an agent with OpenAI provider.
     */
    public function openai(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => 'openai',
            'model' => 'gpt-4o',
        ]);
    }

    /**
     * Create a default agent with ID 'default'.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'agent_id' => 'default',
            'name' => 'Default Agent',
        ]);
    }
}

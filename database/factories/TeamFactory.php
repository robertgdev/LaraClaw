<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Team>
 */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => fake()->unique()->slug(2),
            'name' => fake()->company(),
            'leader_agent_id' => null,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the team is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Create a team with specific agents.
     */
    public function withAgents(array $agentIds, ?string $leaderId = null): static
    {
        return $this->afterCreating(function (Team $team) use ($agentIds, $leaderId) {
            $team->syncAgents($agentIds);
            $team->leader_agent_id = $leaderId ?? $agentIds[0] ?? null;
            $team->save();
        });
    }

    /**
     * Create a team with random agents.
     */
    public function withRandomAgents(int $count = 3): static
    {
        return $this->afterCreating(function (Team $team) use ($count) {
            $agents = Agent::factory()->count($count)->create();
            $team->syncAgents($agents->pluck('agent_id')->toArray());
            $team->leader_agent_id = $agents->first()->agent_id;
            $team->save();
        });
    }
}

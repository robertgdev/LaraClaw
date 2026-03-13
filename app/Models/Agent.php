<?php

namespace App\Models;

use App\Observers\AgentObserver;
use App\TypedCollections\AgentCollection;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([AgentObserver::class])]
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'agent_id',
        'name',
        'provider',
        'model',
        'working_directory',
        'system_prompt',
        'prompt_file',
        'is_active',
        'skills',
        'capabilities',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'skills' => 'array',
        'capabilities' => 'array',
    ];

    /**
     * Get the agent_id as the route key.
     */
    public function getRouteKeyName(): string
    {
        return 'agent_id';
    }

    // ==========================================
    // Relationships
    // ==========================================

    /**
     * Get all teams this agent belongs to via pivot table (many-to-many).
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(
            Team::class,
            'agent_team',
            'agent_id',
            'team_id',
            'agent_id',
            'team_id'
        )->withTimestamps();
    }

    /**
     * Get teams where this agent is the leader.
     */
    public function ledTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'leader_agent_id', 'agent_id');
    }

    /**
     * Get all messages sent by this agent.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class, 'agent_id', 'agent_id');
    }

    /**
     * Get all messages sent by this agent (as from_agent).
     * Used for agent-to-agent messaging.
     */
    public function sentMessages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class, 'agent_id', 'agent_id');
    }

    /**
     * Get all teams this agent belongs to (via JSON column lookup - legacy).
     * Use teams() for pivot table relationship instead.
     */
    public function teamsViaJson(): HasMany
    {
        return $this->hasMany(Team::class, 'leader_agent_id', 'agent_id')
            ->orWhereRaw("json_extract(agents, '$') LIKE ?", ['%"'.$this->agent_id.'"%']);
    }

    /**
     * Get all agents as a keyed array (agent_id => config).
     */
    public static function getAllKeyed(): AgentCollection
    {
        return new AgentCollection(
            self::query()
                ->active()
                ->get()
                ->keyBy('agent_id')
        );
    }

    /**
     * Get a specific agent by agent_id.
     */
    public static function findByAgentId(string $agentId): ?Agent
    {
        return self::getAllKeyed()->get($agentId);
    }

    /**
     * Create or update an agent from config array.
     */
    public static function createFromConfig(string $agentId, array $config): self
    {
        return self::updateOrCreate(
            ['agent_id' => $agentId],
            [
                'name' => $config['name'] ?? $agentId,
                'provider' => $config['provider'] ?? 'anthropic',
                'model' => $config['model'] ?? 'claude-sonnet-4-5',
                'working_directory' => $config['working_directory'] ?? null,
                'system_prompt' => $config['system_prompt'] ?? null,
                'prompt_file' => $config['prompt_file'] ?? null,
                'is_active' => $config['is_active'] ?? true,
            ]
        );
    }

    /**
     * Scope for active agents.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for specific provider.
     */
    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }
}

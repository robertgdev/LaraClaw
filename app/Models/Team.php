<?php

namespace App\Models;

use App\Observers\TeamObserver;
use App\TypedCollections\TeamCollection;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(TeamObserver::class)]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'team_id',
        'name',
        'leader_agent_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'id';

    /**
     * Get the team_id as the route key.
     */
    public function getRouteKeyName(): string
    {
        return 'team_id';
    }

    // ==========================================
    // Relationships
    // ==========================================

    /**
     * Get the leader agent of this team.
     */
    public function leader(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'leader_agent_id', 'agent_id');
    }

    /**
     * Get all agents that belong to this team via pivot table (many-to-many).
     *
     * Note: The pivot table uses agent_id/team_id strings (not model primary keys).
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(
            Agent::class,
            'agent_team',
            'team_id',      // foreign pivot key (column in pivot for this model)
            'agent_id',     // related pivot key (column in pivot for related model)
            'team_id',      // parent key (column in this model)
            'agent_id'      // related key (column in related model)
        )->withTimestamps();
    }

    /**
     * Get all conversations for this team.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'team_id', 'team_id');
    }

    /**
     * Check if an agent is a member of this team (via pivot table).
     */
    public function hasAgent(string $agentId): bool
    {
        return $this->agents()->where('agents.agent_id', $agentId)->exists();
    }

    /**
     * Check if an agent is the leader of this team.
     */
    public function isLeader(string $agentId): bool
    {
        return $this->leader_agent_id === $agentId;
    }

    /**
     * Get the list of agent IDs for this team.
     *
     * @return array<string>
     */
    public function getAgentIds(): array
    {
        return $this->agents()->pluck('agents.agent_id')->toArray();
    }

    /**
     * Sync agents to this team (updates pivot table only).
     *
     * @param  array<int, string>  $agentIds  Array of agent_id strings (e.g., ['agent-1', 'agent-2'])
     */
    public function syncAgents(array $agentIds): void
    {
        // Use direct database operations for pivot table
        // since the pivot table uses agent_id/team_id strings, not model primary keys
        $teamId = $this->team_id;
        $now = now();

        // Delete existing entries
        \Illuminate\Support\Facades\DB::table('agent_team')
            ->where('team_id', $teamId)
            ->delete();

        // Insert new entries
        $insertData = array_map(function ($agentId) use ($teamId, $now) {
            return [
                'agent_id' => $agentId,
                'team_id' => $teamId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $agentIds);

        if (! empty($insertData)) {
            \Illuminate\Support\Facades\DB::table('agent_team')->insert($insertData);
        }
    }

    /**
     * Get all teams as a keyed array (team_id => config).
     */
    public static function getAllKeyed(): TeamCollection
    {
        return new TeamCollection(
            self::query()
                ->active()
                ->with('agents')
                ->get()
                ->keyBy('team_id')
        );
    }

    /**
     * Get a specific team by team_id.
     */
    public static function findByTeamId(string $teamId): ?Team
    {
        return self::getAllKeyed()->get($teamId);
    }

    /**
     * Convert model to config array format (compatible with SettingsService).
     *
     * @return array<string, mixed>
     */
    public function toConfigArray(): array
    {
        return [
            'id' => $this->team_id,
            'name' => $this->name,
            'agents' => $this->getAgentIds(),
            'leader_agent' => $this->leader_agent_id,
            'leader_agent_id' => $this->leader_agent_id,
            'is_active' => $this->is_active,
        ];
    }

    /**
     * Create or update a team from config array.
     *
     * @param  array<string, mixed>  $config
     */
    public static function createFromConfig(string $teamId, array $config): self
    {
        $team = self::updateOrCreate(
            ['team_id' => $teamId],
            [
                'name' => $config['name'] ?? $teamId,
                'leader_agent_id' => $config['leader_agent'] ?? $config['leader_agent_id'] ?? null,
                'is_active' => $config['is_active'] ?? true,
            ]
        );

        // Sync agents to pivot table if agents are provided
        if (isset($config['agents']) && is_array($config['agents'])) {
            $team->syncAgents($config['agents']);
        }

        return $team;
    }

    /**
     * Find the first team that contains a specific agent.
     */
    public static function findTeamForAgent(string $agentId): ?Team
    {
        return self::findTeamsForAgent($agentId)->first();
    }

    /**
     * Find all teams that contain a specific agent.
     */
    public static function findTeamsForAgent(string $agentId): ?TeamCollection
    {
        return new TeamCollection(
            self::getAllKeyed()
                ->filter(fn ($team) => $team->hasAgent($agentId))
                ->keyBy('team_id')
        );
    }

    public static function findTeamForAgentLeader(string $agentId): ?Team
    {
        return self::getAllKeyed()->where('leader_agent_id', $agentId)->first();
    }

    /**
     * Scope for active teams.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

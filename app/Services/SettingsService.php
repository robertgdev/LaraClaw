<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Setting;
use App\Models\Team;
use App\TypedCollections\AgentCollection;
use App\TypedCollections\TeamCollection;
use Illuminate\Support\Arr;

/**
 * Settings Service - Database-backed configuration for LaraClaw.
 *
 * All settings are stored in database tables:
 * - laraclaw_agents: Agent configurations
 * - laraclaw_teams: Team configurations
 * - laraclaw_settings: Key-value settings (workspace, channels, models, etc.)
 */
class SettingsService
{
    // ==========================================
    // Agent Methods (Database-backed)
    // ==========================================

    /**
     * Get all agents as a keyed array.
     * Format: [agent_id => [name, provider, model, working_directory, ...]]
     */
    public function getAgents(): AgentCollection
    {
        return Agent::getAllKeyed();
    }

    /**
     * Get a specific agent by ID.
     */
    public function getAgent(string $agentId): ?Agent
    {
        return Agent::findByAgentId($agentId);
    }

    /**
     * Add or update an agent.
     */
    public function setAgent(string $agentId, array $config): void
    {
        Agent::createFromConfig($agentId, $config);
    }

    /**
     * Remove an agent.
     */
    public function removeAgent(string $agentId): bool
    {
        $agent = Agent::where('agent_id', $agentId)->first();
        if (! $agent) {
            return false;
        }

        $agent->delete();

        return true;
    }

    /**
     * Get an agent model instance by ID.
     */
    public function getAgentModel(string $agentId): ?Agent
    {
        return Agent::where('agent_id', $agentId)->first();
    }

    /**
     * Get the default agent (uses agents.default_agent_id setting, or falls back to first agent).
     */
    public function getDefaultAgent(): ?Agent
    {
        // First check if there's a configured default agent ID
        $defaultAgentId = $this->get('agents.default_agent_id');

        if ($defaultAgentId) {
            $agent = $this->getAgent($defaultAgentId);
            if ($agent) {
                return $agent;
            }
        }

        // Fallback: check for agent with ID 'default'
        $agents = $this->getAgents();
        if ($agents->has('default')) {
            return $agents->get('default');
        }

        // Final fallback: return first agent
        return $agents->first();
    }

    /**
     * Get the default agent ID.
     */
    public function getDefaultAgentId(): ?string
    {
        $agent = $this->getDefaultAgent();

        return $agent?->agent_id;
    }

    // ==========================================
    // Team Methods (Database-backed)
    // ==========================================

    /**
     * Get all teams as a keyed array.
     * Format: [team_id => [name, agents, leader_agent, ...]]
     */
    public function getTeams(): TeamCollection
    {
        return Team::getAllKeyed();
    }

    /**
     * Get a specific team by ID.
     */
    public function getTeam(string $teamId): ?Team
    {
        return Team::findByTeamId($teamId);
    }

    /**
     * Add or update a team.
     */
    public function setTeam(string $teamId, array $config): void
    {
        Team::createFromConfig($teamId, $config);
    }

    /**
     * Remove a team.
     */
    public function removeTeam(string $teamId): bool
    {
        $team = Team::where('team_id', $teamId)->first();
        if (! $team) {
            return false;
        }

        $team->delete();

        return true;
    }

    /**
     * Get a team model instance by ID.
     */
    public function getTeamModel(string $teamId): ?Team
    {
        return Team::where('team_id', $teamId)->first();
    }

    /**
     * Find a team that contains a specific agent.
     */
    public function findTeamForAgent(string $agentId): ?Team
    {
        return Team::findTeamForAgent($agentId);
    }

    // ==========================================
    // Settings Methods (Database-backed key-value)
    // ==========================================

    /**
     * Get all settings as a nested array.
     */
    public function all(): array
    {
        $flatSettings = Setting::getAllKeyed();

        // Convert flat dot-notation to nested array
        $nested = [];
        foreach ($flatSettings as $key => $value) {
            Arr::set($nested, $key, $value);
        }

        return $nested;
    }

    /**
     * Get a setting value using dot notation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Setting::findByKey($key, $default);
    }

    /**
     * Set a setting value using dot notation.
     */
    public function set(string $key, mixed $value): void
    {
        Setting::set($key, $value);
    }

    /**
     * Set multiple settings at once.
     */
    public function setMany(array $settings): void
    {
        Setting::setMany($settings);
    }

    /**
     * Delete a setting.
     */
    public function remove(string $key): bool
    {
        return Setting::remove($key);
    }

    /**
     * Initialize default settings if they don't exist.
     */
    public function initialize(): bool
    {
        $count = Setting::initializeDefaults();

        return $count > 0;
    }

    /**
     * Ensure settings are initialized.
     */
    public function ensureInitialized(): void
    {
        Setting::initializeDefaults();
    }

    /**
     * Reload/clear all caches.
     *
     * Since this service uses direct database queries without caching,
     * this method exists for API compatibility but doesn't need to do anything.
     */
    public function reload(): void
    {
        // No-op - this service uses direct database queries without caching
        // Method exists for API compatibility
    }

    // ==========================================
    // Convenience Methods
    // ==========================================

    /**
     * Get workspace path.
     */
    public function getWorkspacePath(): string
    {
        return $this->get('workspace.path', config('laraclaw.workspace.path'));
    }

    /**
     * Get workspace name.
     */
    public function getWorkspaceName(): string
    {
        return $this->get('workspace.name', config('laraclaw.workspace.name'));
    }

    /**
     * Get enabled channels.
     */
    public function getEnabledChannels(): array
    {
        return $this->get('channels.enabled', []);
    }

    /**
     * Get polling interval for a specific channel.
     */
    public function getPollingInterval(string $channel): int
    {
        return (int) $this->get("channels.{$channel}.polling_interval", 5);
    }

    /**
     * Get the default model provider.
     */
    public function getDefaultProvider(): string
    {
        return $this->get('models.provider', 'anthropic');
    }

    /**
     * Get the default model for a provider.
     */
    public function getDefaultModel(string $provider): string
    {
        return $this->get("models.{$provider}.model", 'default');
    }

    /**
     * Get heartbeat interval.
     */
    public function getHeartbeatInterval(): int
    {
        return (int) $this->get('monitoring.heartbeat_interval', 300);
    }

    // ==========================================
    // Export/Import Methods (for backup/restore)
    // ==========================================

    /**
     * Export all settings to an array (for backup).
     */
    public function export(): array
    {
        return [
            'workspace' => [
                'path' => $this->getWorkspacePath(),
                'name' => $this->getWorkspaceName(),
            ],
            'channels' => [
                'enabled' => $this->getEnabledChannels(),
            ],
            'models' => [
                'provider' => $this->getDefaultProvider(),
                'anthropic' => [
                    'model' => $this->getDefaultModel('anthropic'),
                ],
            ],
            'agents' => $this->getAgents(),
            'teams' => $this->getTeams(),
            'monitoring' => [
                'heartbeat_interval' => $this->getHeartbeatInterval(),
            ],
        ];
    }

    /**
     * Import settings from an array (for restore).
     */
    public function import(array $settings): void
    {
        // Import scalar settings
        if (isset($settings['workspace']['path'])) {
            $this->set('workspace.path', $settings['workspace']['path']);
        }
        if (isset($settings['workspace']['name'])) {
            $this->set('workspace.name', $settings['workspace']['name']);
        }
        if (isset($settings['channels']['enabled'])) {
            $this->set('channels.enabled', $settings['channels']['enabled']);
        }
        if (isset($settings['models']['provider'])) {
            $this->set('models.provider', $settings['models']['provider']);
        }
        if (isset($settings['models']['anthropic']['model'])) {
            $this->set('models.anthropic.model', $settings['models']['anthropic']['model']);
        }
        if (isset($settings['monitoring']['heartbeat_interval'])) {
            $this->set('monitoring.heartbeat_interval', $settings['monitoring']['heartbeat_interval']);
        }

        // Import agents
        foreach ($settings['agents'] ?? [] as $agentId => $config) {
            $this->setAgent($agentId, $config);
        }

        // Import teams
        foreach ($settings['teams'] ?? [] as $teamId => $config) {
            $this->setTeam($teamId, $config);
        }
    }
}

<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Setting;
use App\Models\Team;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->service = new SettingsService;

    // Clear models and cache
    Agent::query()->delete();
    Team::query()->delete();
    Setting::query()->delete();
    Cache::flush();
});

describe('SettingsService', function () {
    describe('getAgents', function () {
        it('returns all agents as keyed array', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);

            // Act
            $result = $this->service->getAgents();

            // Assert
            expect($result)->toHaveKey('agent-1')
                ->and($result['agent-1']['name'])->toBe('Agent 1');
        });
    });

    describe('getAgent', function () {
        it('returns specific agent by id', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);

            // Act
            $result = $this->service->getAgent('agent-1');

            // Assert
            expect($result)->not->toBeNull()
                ->and($result['name'])->toBe('Agent 1');
        });

        it('returns null for non-existent agent', function () {
            // Act
            $result = $this->service->getAgent('nonexistent');

            // Assert
            expect($result)->toBeNull();
        });
    });

    describe('setAgent', function () {
        it('creates or updates an agent', function () {
            // Arrange
            $agentId = 'new-agent';
            $config = ['name' => 'New Agent', 'provider' => 'anthropic'];

            // Act
            $this->service->setAgent($agentId, $config);

            // Assert
            $agent = Agent::where('agent_id', $agentId)->first();
            expect($agent)->not->toBeNull()
                ->and($agent->name)->toBe('New Agent')
                ->and($agent->provider)->toBe('anthropic');
        });
    });

    describe('removeAgent', function () {
        it('removes an existing agent', function () {
            // Arrange
            Agent::createFromConfig('agent-to-remove', ['name' => 'Agent to Remove']);

            // Act
            $result = $this->service->removeAgent('agent-to-remove');

            // Assert
            expect($result)->toBeTrue()
                ->and(Agent::where('agent_id', 'agent-to-remove')->exists())->toBeFalse();
        });

        it('returns false for non-existent agent', function () {
            // Act
            $result = $this->service->removeAgent('nonexistent');

            // Assert
            expect($result)->toBeFalse();
        });

        it('cleans up pivot table entries when agent is deleted', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Agent::createFromConfig('agent-2', ['name' => 'Agent 2']);

            Team::createFromConfig('test-team', [
                'name' => 'Test Team',
                'agents' => ['agent-1', 'agent-2'],
                'leader_agent' => 'agent-1',
            ]);

            // Verify pivot entries exist for agent-1
            $pivotCountBefore = \Illuminate\Support\Facades\DB::table('agent_team')
                ->where('agent_id', 'agent-1')
                ->count();
            expect($pivotCountBefore)->toBe(1);

            // Act
            $result = $this->service->removeAgent('agent-1');

            // Assert - pivot entries for agent-1 should be cleaned up
            $pivotCountAfter = \Illuminate\Support\Facades\DB::table('agent_team')
                ->where('agent_id', 'agent-1')
                ->count();
            expect($result)->toBeTrue()
                ->and($pivotCountAfter)->toBe(0);

            // Pivot entries for agent-2 should still exist
            $pivotCountAgent2 = \Illuminate\Support\Facades\DB::table('agent_team')
                ->where('agent_id', 'agent-2')
                ->count();
            expect($pivotCountAgent2)->toBe(1);
        });
    });

    describe('getAgentModel', function () {
        it('returns agent model instance', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);

            // Act
            $result = $this->service->getAgentModel('agent-1');

            // Assert
            expect($result)->toBeInstanceOf(Agent::class)
                ->and($result->agent_id)->toBe('agent-1');
        });
    });

    describe('getDefaultAgent', function () {
        it('returns configured default agent', function () {
            // Arrange
            Agent::createFromConfig('default', ['name' => 'Default Agent']);
            Setting::set('agents.default_agent_id', 'default');

            // Act
            $result = $this->service->getDefaultAgent();

            // Assert
            expect($result)->not->toBeNull()
                ->and($result['name'])->toBe('Default Agent');
        });

        it('falls back to agent with id default', function () {
            // Arrange
            Agent::createFromConfig('default', ['name' => 'Default Agent']);

            // Act
            $result = $this->service->getDefaultAgent();

            // Assert
            expect($result)->not->toBeNull()
                ->and($result->agent_id)->toBe('default');
        });

        it('returns first agent when no default configured', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'First Agent']);

            // Act
            $result = $this->service->getDefaultAgent();

            // Assert
            expect($result)->not->toBeNull()
                ->and($result['name'])->toBe('First Agent');
        });

        it('returns null when no agents exist', function () {
            // Act
            $result = $this->service->getDefaultAgent();

            // Assert
            expect($result)->toBeNull();
        });
    });

    describe('getDefaultAgentId', function () {
        it('returns default agent id', function () {
            // Arrange
            Agent::createFromConfig('default', ['name' => 'Default Agent']);
            Setting::set('agents.default_agent_id', 'default');

            // Act
            $result = $this->service->getDefaultAgentId();

            // Assert
            expect($result)->toBe('default');
        });

        it('returns null when no default agent', function () {
            // Act
            $result = $this->service->getDefaultAgentId();

            // Assert
            expect($result)->toBeNull();
        });
    });

    describe('getTeams', function () {
        it('returns all teams as keyed array', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'leader_agent' => 'agent-1',
            ]);

            // Act
            $result = $this->service->getTeams();

            // Assert
            expect($result)->toHaveKey('team-1')
                ->and($result['team-1']['name'])->toBe('Team 1');
        });
    });

    describe('getTeam', function () {
        it('returns specific team by id', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'leader_agent' => 'agent-1',
            ]);

            // Act
            $result = $this->service->getTeam('team-1');

            // Assert
            expect($result)->not->toBeNull()
                ->and($result['name'])->toBe('Team 1');
        });
    });

    describe('setTeam', function () {
        it('creates or updates a team', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            $teamId = 'new-team';
            $config = ['name' => 'New Team', 'agents' => ['agent-1'], 'leader_agent' => 'agent-1'];

            // Act
            $this->service->setTeam($teamId, $config);

            // Assert
            $team = Team::where('team_id', $teamId)->first();
            expect($team)->not->toBeNull()
                ->and($team->name)->toBe('New Team');
        });

        it('populates pivot table when team is created with agents', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Agent::createFromConfig('agent-2', ['name' => 'Agent 2']);
            $teamId = 'test-team';
            $config = [
                'name' => 'Test Team',
                'agents' => ['agent-1', 'agent-2'],
                'leader_agent' => 'agent-1',
            ];

            // Act
            $this->service->setTeam($teamId, $config);

            // Assert - Check pivot table entries
            $pivotEntries = \Illuminate\Support\Facades\DB::table('agent_team')
                ->where('team_id', $teamId)
                ->get();

            expect($pivotEntries)->toHaveCount(2)
                ->and($pivotEntries->pluck('agent_id')->toArray())->toContain('agent-1', 'agent-2');
        });

        it('populates pivot table when team is updated with new agents', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Agent::createFromConfig('agent-2', ['name' => 'Agent 2']);
            Agent::createFromConfig('agent-3', ['name' => 'Agent 3']);

            // Create initial team
            $this->service->setTeam('test-team', [
                'name' => 'Test Team',
                'agents' => ['agent-1', 'agent-2'],
                'leader_agent' => 'agent-1',
            ]);

            // Act - Update team with different agents
            $this->service->setTeam('test-team', [
                'name' => 'Updated Team',
                'agents' => ['agent-2', 'agent-3'],
                'leader_agent' => 'agent-2',
            ]);

            // Assert - Check pivot table entries are updated
            $pivotEntries = \Illuminate\Support\Facades\DB::table('agent_team')
                ->where('team_id', 'test-team')
                ->get();

            expect($pivotEntries)->toHaveCount(2)
                ->and($pivotEntries->pluck('agent_id')->toArray())->toContain('agent-2', 'agent-3')
                ->and($pivotEntries->pluck('agent_id')->toArray())->not->toContain('agent-1');
        });

        it('allows accessing agents via eloquent relationship', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Agent::createFromConfig('agent-2', ['name' => 'Agent 2']);

            // Act
            $this->service->setTeam('test-team', [
                'name' => 'Test Team',
                'agents' => ['agent-1', 'agent-2'],
                'leader_agent' => 'agent-1',
            ]);

            // Assert - Check Eloquent relationship works
            $team = Team::where('team_id', 'test-team')->first();
            $agentsViaRelationship = $team->agents()->get();

            expect($agentsViaRelationship)->toHaveCount(2)
                ->and($agentsViaRelationship->pluck('agent_id')->toArray())->toContain('agent-1', 'agent-2');
        });

        it('allows accessing teams from agent via eloquent relationship', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Agent::createFromConfig('agent-2', ['name' => 'Agent 2']);

            // Act
            $this->service->setTeam('test-team', [
                'name' => 'Test Team',
                'agents' => ['agent-1', 'agent-2'],
                'leader_agent' => 'agent-1',
            ]);

            // Assert - Check reverse Eloquent relationship works
            $agent = Agent::where('agent_id', 'agent-1')->first();
            $teamsViaRelationship = $agent->teams()->get();

            expect($teamsViaRelationship)->toHaveCount(1)
                ->and($teamsViaRelationship->first()->team_id)->toBe('test-team');
        });
    });

    describe('removeTeam', function () {
        it('removes an existing team', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Team::createFromConfig('team-to-remove', [
                'name' => 'Team to Remove',
                'leader_agent' => 'agent-1',
            ]);

            // Act
            $result = $this->service->removeTeam('team-to-remove');

            // Assert
            expect($result)->toBeTrue()
                ->and(Team::where('team_id', 'team-to-remove')->exists())->toBeFalse();
        });

        it('cleans up pivot table entries when team is deleted', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Agent::createFromConfig('agent-2', ['name' => 'Agent 2']);

            Team::createFromConfig('team-to-remove', [
                'name' => 'Team to Remove',
                'agents' => ['agent-1', 'agent-2'],
                'leader_agent' => 'agent-1',
            ]);

            // Verify pivot entries exist
            $pivotCountBefore = \Illuminate\Support\Facades\DB::table('agent_team')
                ->where('team_id', 'team-to-remove')
                ->count();
            expect($pivotCountBefore)->toBe(2);

            // Act
            $result = $this->service->removeTeam('team-to-remove');

            // Assert - pivot entries should be cleaned up
            $pivotCountAfter = \Illuminate\Support\Facades\DB::table('agent_team')
                ->where('team_id', 'team-to-remove')
                ->count();
            expect($result)->toBeTrue()
                ->and($pivotCountAfter)->toBe(0);
        });
    });

    describe('getTeamModel', function () {
        it('returns team model instance', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'leader_agent' => 'agent-1',
            ]);

            // Act
            $result = $this->service->getTeamModel('team-1');

            // Assert
            expect($result)->toBeInstanceOf(Team::class)
                ->and($result->team_id)->toBe('team-1');
        });
    });

    describe('findTeamForAgent', function () {
        it('returns team containing agent', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'agents' => ['agent-1'],
                'leader_agent' => 'agent-1',
            ]);

            // Act
            $result = $this->service->findTeamForAgent('agent-1');

            // Assert
            expect($result)->not->toBeNull()
                ->and($result->team_id)->toBe('team-1');
        });
    });

    describe('findTeamModelForAgent', function () {
        it('returns team model containing agent', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'agents' => ['agent-1'],
                'leader_agent' => 'agent-1',
            ]);

            // Act
            $result = $this->service->findTeamForAgent('agent-1');

            // Assert
            expect($result)->toBeInstanceOf(Team::class);
        });
    });

    describe('all', function () {
        it('returns all settings as nested array', function () {
            // Arrange
            Setting::set('workspace.path', '/tmp/laraclaw');
            Setting::set('workspace.name', 'Test');

            // Act
            $result = $this->service->all();

            // Assert
            expect($result)->toBeArray()
                ->toHaveKey('workspace')
                ->and($result['workspace']['path'])->toBe('/tmp/laraclaw');
        });
    });

    describe('get', function () {
        it('returns setting value by key', function () {
            // Arrange
            Setting::set('workspace.path', '/tmp/laraclaw');

            // Act
            $result = $this->service->get('workspace.path');

            // Assert
            expect($result)->toBe('/tmp/laraclaw');
        });

        it('returns default for missing key', function () {
            // Act
            $result = $this->service->get('nonexistent', 'default_value');

            // Assert
            expect($result)->toBe('default_value');
        });
    });

    describe('set', function () {
        it('sets a setting value', function () {
            // Arrange
            $key = 'workspace.path';
            $value = '/new/path';

            // Act
            $this->service->set($key, $value);

            // Assert
            expect(Setting::findByKey($key))->toBe($value);
        });
    });

    describe('setMany', function () {
        it('sets multiple settings at once', function () {
            // Arrange
            $settings = [
                'workspace.path' => '/tmp/laraclaw',
                'workspace.name' => 'Test',
            ];

            // Act
            $this->service->setMany($settings);

            // Assert
            expect(Setting::findByKey('workspace.path'))->toBe('/tmp/laraclaw')
                ->and(Setting::findByKey('workspace.name'))->toBe('Test');
        });
    });

    describe('remove', function () {
        it('removes a setting', function () {
            // Arrange
            Setting::set('workspace.path', '/tmp/laraclaw');

            // Act
            $result = $this->service->remove('workspace.path');

            // Assert
            expect($result)->toBeTrue()
                ->and(Setting::findByKey('workspace.path'))->toBeNull();
        });
    });

    describe('initialize', function () {
        it('initializes default settings', function () {
            // Act
            $result = $this->service->initialize();

            // Assert
            expect($result)->toBeTrue();
        });

        it('returns false when no settings initialized', function () {
            // Arrange - pre-initialize all defaults
            $this->service->initialize();

            // Act - try to initialize again
            $result = $this->service->initialize();

            // Assert
            expect($result)->toBeFalse();
        });
    });

    describe('ensureInitialized', function () {
        it('ensures settings are initialized', function () {
            // Act
            $this->service->ensureInitialized();

            // Assert - no exception thrown, settings exist
            expect(Setting::count())->toBeGreaterThan(0);
        });
    });

    describe('reload', function () {
        it('clears all caches', function () {
            // Arrange - create some data to cache
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'leader_agent' => 'agent-1',
            ]);
            Setting::set('test.key', 'value');

            // Populate caches
            $this->service->getAgents();
            $this->service->getTeams();
            $this->service->get('test.key');

            // Act
            $this->service->reload();

            // Assert - no exception thrown
            expect(true)->toBeTrue();
        });
    });

    describe('getWorkspacePath', function () {
        it('returns workspace path from settings', function () {
            // Arrange
            Setting::set('workspace.path', '/tmp/laraclaw');

            // Act
            $result = $this->service->getWorkspacePath();

            // Assert
            expect($result)->toBe('/tmp/laraclaw');
        });
    });

    describe('getWorkspaceName', function () {
        it('returns workspace name from settings', function () {
            // Arrange
            Setting::set('workspace.name', 'Test Workspace');

            // Act
            $result = $this->service->getWorkspaceName();

            // Assert
            expect($result)->toBe('Test Workspace');
        });
    });

    describe('getEnabledChannels', function () {
        it('returns enabled channels from settings', function () {
            // Arrange
            Setting::set('channels.enabled', ['telegram', 'discord']);

            // Act
            $result = $this->service->getEnabledChannels();

            // Assert
            expect($result)->toBe(['telegram', 'discord']);
        });
    });

    describe('getDefaultProvider', function () {
        it('returns default provider from settings', function () {
            // Arrange
            Setting::set('models.provider', 'openai');

            // Act
            $result = $this->service->getDefaultProvider();

            // Assert
            expect($result)->toBe('openai');
        });
    });

    describe('getDefaultModel', function () {
        it('returns default model for provider', function () {
            // Arrange
            $provider = 'anthropic';
            Setting::set("models.{$provider}.model", 'claude-sonnet-4-5');

            // Act
            $result = $this->service->getDefaultModel($provider);

            // Assert
            expect($result)->toBe('claude-sonnet-4-5');
        });
    });

    describe('getHeartbeatInterval', function () {
        it('returns heartbeat interval from settings', function () {
            // Arrange
            Setting::set('monitoring.heartbeat_interval', 600);

            // Act
            $result = $this->service->getHeartbeatInterval();

            // Assert
            expect($result)->toBe(600);
        });
    });

    describe('export', function () {
        it('exports all settings to array', function () {
            // Arrange
            Setting::set('workspace.path', '/tmp/laraclaw');
            Setting::set('workspace.name', 'Test');
            Setting::set('channels.enabled', ['telegram']);
            Setting::set('models.provider', 'anthropic');
            Setting::set('monitoring.heartbeat_interval', 300);

            // Act
            $result = $this->service->export();

            // Assert
            expect($result)->toBeArray()
                ->toHaveKey('workspace')
                ->toHaveKey('channels')
                ->toHaveKey('models')
                ->toHaveKey('agents')
                ->toHaveKey('teams')
                ->toHaveKey('monitoring');
        });
    });

    describe('import', function () {
        it('imports settings from array', function () {
            // Arrange
            $settings = [
                'workspace' => [
                    'path' => '/tmp/laraclaw',
                    'name' => 'Test',
                ],
                'channels' => [
                    'enabled' => ['telegram'],
                ],
                'models' => [
                    'provider' => 'anthropic',
                    'anthropic' => [
                        'model' => 'claude-sonnet-4-5',
                    ],
                ],
                'monitoring' => [
                    'heartbeat_interval' => 300,
                ],
                'agents' => [],
                'teams' => [],
            ];

            // Act
            $this->service->import($settings);

            // Assert
            expect(Setting::findByKey('workspace.path'))->toBe('/tmp/laraclaw')
                ->and(Setting::findByKey('workspace.name'))->toBe('Test');
        });
    });
});

// Tests for cascading soft deletes
describe('Cascading Soft Deletes', function () {
    beforeEach(function () {
        $this->service = new SettingsService;

        // Clear models and cache
        Agent::query()->delete();
        Team::query()->delete();
        Setting::query()->delete();
        ConversationMessage::query()->delete();
        Conversation::query()->delete();
        Cache::flush();
    });

    describe('Agent soft delete cascading', function () {
        it('soft deletes messages when agent is soft deleted', function () {
            // Arrange
            $agent = Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);

            // Create a conversation first (required for foreign key)
            $conversation = \App\Models\Conversation::create([
                'channel' => 'telegram',
                'sender' => 'user1',
            ]);

            // Create messages for this agent
            $message1 = ConversationMessage::create([
                'conversation_id' => $conversation->conversation_id,
                'channel' => 'telegram',
                'sender' => 'user1',
                'message' => 'Test message 1',
                'agent_id' => 'agent-1',
            ]);
            $message2 = ConversationMessage::create([
                'conversation_id' => $conversation->conversation_id,
                'channel' => 'telegram',
                'sender' => 'user1',
                'message' => 'Test message 2',
                'agent_id' => 'agent-1',
            ]);

            // Verify messages exist
            expect(ConversationMessage::where('agent_id', 'agent-1')->count())->toBe(2);

            // Act - soft delete the agent
            $agent->delete();

            // Assert - agent is soft deleted
            expect(Agent::where('agent_id', 'agent-1')->exists())->toBeFalse()
                ->and(Agent::withTrashed()->where('agent_id', 'agent-1')->exists())->toBeTrue();

            // Assert - messages are soft deleted
            expect(ConversationMessage::where('agent_id', 'agent-1')->exists())->toBeFalse()
                ->and(ConversationMessage::withTrashed()->where('agent_id', 'agent-1')->count())->toBe(2);
        });

        it('soft deletes led teams when agent is soft deleted', function () {
            // Arrange
            $leader = Agent::createFromConfig('leader-agent', ['name' => 'Leader']);
            Agent::createFromConfig('member-agent', ['name' => 'Member']);

            $team = Team::createFromConfig('led-team', [
                'name' => 'Led Team',
                'agents' => ['leader-agent', 'member-agent'],
                'leader_agent' => 'leader-agent',
            ]);

            // Verify team exists
            expect(Team::where('team_id', 'led-team')->exists())->toBeTrue();

            // Act - soft delete the leader agent
            $leader->delete();

            // Assert - team is soft deleted because leader was deleted
            expect(Team::where('team_id', 'led-team')->exists())->toBeFalse()
                ->and(Team::withTrashed()->where('team_id', 'led-team')->exists())->toBeTrue();
        });

        it('restores messages when agent is restored', function () {
            // Arrange
            $agent = Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);

            // Create a conversation first (required for foreign key)
            $conversation = \App\Models\Conversation::create([
                'channel' => 'telegram',
                'sender' => 'user1',
            ]);

            $message = ConversationMessage::create([
                'conversation_id' => $conversation->conversation_id,
                'channel' => 'telegram',
                'sender' => 'user1',
                'message' => 'Test message',
                'agent_id' => 'agent-1',
            ]);

            // Soft delete the agent
            $agent->delete();

            // Verify message is soft deleted
            expect(ConversationMessage::where('agent_id', 'agent-1')->exists())->toBeFalse();

            // Act - restore the agent
            $agent->restore();

            // Assert - agent is restored
            expect(Agent::where('agent_id', 'agent-1')->exists())->toBeTrue();

            // Assert - messages are restored
            expect(ConversationMessage::where('agent_id', 'agent-1')->exists())->toBeTrue();
        });

        it('restores led teams when agent is restored', function () {
            // Arrange
            $leader = Agent::createFromConfig('leader-agent', ['name' => 'Leader']);

            $team = Team::createFromConfig('led-team', [
                'name' => 'Led Team',
                'agents' => ['leader-agent'],
                'leader_agent' => 'leader-agent',
            ]);

            // Soft delete the leader
            $leader->delete();

            // Verify team is soft deleted
            expect(Team::where('team_id', 'led-team')->exists())->toBeFalse();

            // Act - restore the leader
            $leader->restore();

            // Assert - team is restored
            expect(Team::where('team_id', 'led-team')->exists())->toBeTrue();
        });
    });

    describe('Team soft delete cascading', function () {
        it('soft deletes conversations when team is soft deleted', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);

            $team = Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'agents' => ['agent-1'],
                'leader_agent' => 'agent-1',
            ]);

            $conversation = Conversation::create([
                'channel' => 'telegram',
                'sender' => 'user1',
                'team_id' => 'team-1',
            ]);

            // Verify conversation exists
            expect(Conversation::where('team_id', 'team-1')->exists())->toBeTrue();

            // Act - soft delete the team
            $team->delete();

            // Assert - team is soft deleted
            expect(Team::where('team_id', 'team-1')->exists())->toBeFalse()
                ->and(Team::withTrashed()->where('team_id', 'team-1')->exists())->toBeTrue();

            // Assert - conversation is soft deleted
            expect(Conversation::where('team_id', 'team-1')->exists())->toBeFalse()
                ->and(Conversation::withTrashed()->where('team_id', 'team-1')->exists())->toBeTrue();
        });

        it('restores conversations when team is restored', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);

            $team = Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'agents' => ['agent-1'],
                'leader_agent' => 'agent-1',
            ]);

            $conversation = Conversation::create([
                'channel' => 'telegram',
                'sender' => 'user1',
                'team_id' => 'team-1',
            ]);

            // Soft delete the team
            $team->delete();

            // Verify conversation is soft deleted
            expect(Conversation::where('team_id', 'team-1')->exists())->toBeFalse();

            // Act - restore the team
            $team->restore();

            // Assert - conversation is restored
            expect(Conversation::where('team_id', 'team-1')->exists())->toBeTrue();
        });
    });
});

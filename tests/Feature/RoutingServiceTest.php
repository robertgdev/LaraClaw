<?php

use App\DTOs\RoutingResultDTO;
use App\Models\Agent;
use App\Models\Team;
use App\Services\IntentClassificationService;
use App\Services\RoutingService;
use App\Services\SettingsService;
use App\Services\SkillSearchService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->settings = app(SettingsService::class);
    $this->intentService = app(IntentClassificationService::class);
    $this->skillService = app(SkillSearchService::class);
    $this->routingService = new RoutingService(
        $this->settings,
        $this->intentService,
        $this->skillService
    );

    // Clear models and cache
    Agent::query()->delete();
    Team::query()->delete();
    Cache::flush();
});

describe('RoutingService', function () {
    describe('findTeamForAgent', function () {
        it('returns team when agent is found', function () {
            // Arrange - create agent and team in database
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'agents' => ['agent-1'],
                'leader_agent' => 'agent-1',
            ]);

            // Act
            $result = $this->routingService->findTeamForAgent('agent-1');

            // Assert
            expect($result)->not->toBeNull()
                ->and($result['team_id'])->toBe('team-1');
        });

        it('returns null when agent is not in any team', function () {
            // Arrange - create agent without team
            Agent::createFromConfig('lonely-agent', ['name' => 'Lonely Agent']);

            // Act
            $result = $this->routingService->findTeamForAgent('lonely-agent');

            // Assert
            expect($result)->toBeNull();
        });
    });

    describe('findTeamModelForAgent', function () {
        it('returns team model when found', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'agents' => ['agent-1'],
                'leader_agent' => 'agent-1',
            ]);

            // Act
            $result = $this->routingService->findTeamForAgent('agent-1');

            // Assert
            expect($result)->not->toBeNull()
                ->and($result)->toBeInstanceOf(Team::class);
        });
    });

    describe('isTeammate', function () {
        it('returns true for valid teammate', function () {
            // Arrange
            $mentionedId = 'agent-2';
            $currentAgentId = 'agent-1';
            $teamId = 'team-1';

            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Agent::createFromConfig('agent-2', ['name' => 'Agent 2']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'agents' => ['agent-1', 'agent-2'],
                'leader_agent' => 'agent-1',
            ]);

            $teams = $this->settings->getTeams();
            $agents = $this->settings->getAgents();

            // Act
            $result = $this->routingService->isTeammate(
                $mentionedId,
                $currentAgentId,
                $teamId,
                $teams,
                $agents
            );

            // Assert
            expect($result)->toBeTrue();
        });

        it('returns false when mentioning self', function () {
            // Arrange
            $mentionedId = 'agent-1';
            $currentAgentId = 'agent-1';
            $teamId = 'team-1';

            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Agent::createFromConfig('agent-2', ['name' => 'Agent 2']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'agents' => ['agent-1', 'agent-2'],
                'leader_agent' => 'agent-1',
            ]);

            $teams = $this->settings->getTeams();
            $agents = $this->settings->getAgents();

            // Act
            $result = $this->routingService->isTeammate(
                $mentionedId,
                $currentAgentId,
                $teamId,
                $teams,
                $agents
            );

            // Assert
            expect($result)->toBeFalse();
        });

        it('returns false when agent not in team', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Agent::createFromConfig('agent-2', ['name' => 'Agent 2']);
            Agent::createFromConfig('agent-3', ['name' => 'Agent 3']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'agents' => ['agent-1', 'agent-2'],
                'leader_agent' => 'agent-1',
            ]);

            $teams = $this->settings->getTeams();
            $agents = $this->settings->getAgents();

            // Act
            $result = $this->routingService->isTeammate(
                'agent-3',
                'agent-1',
                'team-1',
                $teams,
                $agents
            );

            // Assert
            expect($result)->toBeFalse();
        });

        it('returns false when team not found', function () {
            // Arrange
            $agents = new \App\TypedCollections\AgentCollection;
            $teams = new \App\TypedCollections\TeamCollection;

            // Act
            $result = $this->routingService->isTeammate(
                'agent-2',
                'agent-1',
                'nonexistent',
                $teams,
                $agents
            );

            // Assert
            expect($result)->toBeFalse();
        });
    });

    describe('isTeammateUsingModel', function () {
        it('returns true for valid teammate using model', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Agent::createFromConfig('agent-2', ['name' => 'Agent 2']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'agents' => ['agent-1', 'agent-2'],
                'leader_agent' => 'agent-1',
            ]);

            $team = Team::where('team_id', 'team-1')->first();

            // Act
            $result = $this->routingService->isTeammateUsingModel(
                'agent-2',
                'agent-1',
                $team
            );

            // Assert
            expect($result)->toBeTrue();
        });
    });

    describe('extractTeammateMentions', function () {
        it('extracts single teammate mention', function () {
            // Arrange
            $response = '[@agent-2: Can you help with this?]';
            $currentAgentId = 'agent-1';
            $teamId = 'team-1';

            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Agent::createFromConfig('agent-2', ['name' => 'Agent 2']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'agents' => ['agent-1', 'agent-2'],
                'leader_agent' => 'agent-1',
            ]);

            $teams = $this->settings->getTeams();
            $agents = $this->settings->getAgents();

            // Act
            $result = $this->routingService->extractTeammateMentions(
                $response,
                $currentAgentId,
                $teamId,
                $teams,
                $agents
            );

            // Assert
            expect($result)->toHaveCount(1)
                ->and($result[0]['teammateId'])->toBe('agent-2');
        });

        it('extracts multiple teammate mentions', function () {
            // Arrange
            $response = '[@agent-2: Help!] [@agent-3: Also help!]';
            $currentAgentId = 'agent-1';
            $teamId = 'team-1';

            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Agent::createFromConfig('agent-2', ['name' => 'Agent 2']);
            Agent::createFromConfig('agent-3', ['name' => 'Agent 3']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'agents' => ['agent-1', 'agent-2', 'agent-3'],
                'leader_agent' => 'agent-1',
            ]);

            $teams = $this->settings->getTeams();
            $agents = $this->settings->getAgents();

            // Act
            $result = $this->routingService->extractTeammateMentions(
                $response,
                $currentAgentId,
                $teamId,
                $teams,
                $agents
            );

            // Assert
            expect($result)->toHaveCount(2);
        });

        it('returns empty array when no mentions found', function () {
            // Arrange
            $response = 'No mentions here';
            $agents = new \App\TypedCollections\AgentCollection;
            $teams = new \App\TypedCollections\TeamCollection;

            // Act
            $result = $this->routingService->extractTeammateMentions(
                $response,
                'agent-1',
                'team-1',
                $teams,
                $agents
            );

            // Assert
            expect($result)->toBeEmpty();
        });

        it('handles comma-separated agent IDs', function () {
            // Arrange
            $response = '[@agent-2,agent-3: Both of you help!]';
            $currentAgentId = 'agent-1';
            $teamId = 'team-1';

            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Agent::createFromConfig('agent-2', ['name' => 'Agent 2']);
            Agent::createFromConfig('agent-3', ['name' => 'Agent 3']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'agents' => ['agent-1', 'agent-2', 'agent-3'],
                'leader_agent' => 'agent-1',
            ]);

            $teams = $this->settings->getTeams();
            $agents = $this->settings->getAgents();

            // Act
            $result = $this->routingService->extractTeammateMentions(
                $response,
                $currentAgentId,
                $teamId,
                $teams,
                $agents
            );

            // Assert
            expect($result)->toHaveCount(2);
        });
    });

    describe('getAgentResetFlag', function () {
        it('returns correct reset flag path', function () {
            // Arrange
            $agentId = 'test-agent';
            $workspacePath = '/tmp/laraclaw';

            $this->settings->set('workspace.path', $workspacePath);

            // Act
            $result = $this->routingService->getAgentResetFlag($agentId);

            // Assert
            expect($result)->toBe('/tmp/laraclaw/test-agent/reset_flag');
        });
    });

    describe('detectMultipleAgents', function () {
        it('detects multiple agent mentions', function () {
            // Arrange
            $message = '@agent-1 @agent-2 please help';

            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Agent::createFromConfig('agent-2', ['name' => 'Agent 2']);

            $agents = $this->settings->getAgents();
            $teams = $this->settings->getTeams();

            // Act
            $result = $this->routingService->detectMultipleAgents($message, $agents, $teams);

            // Assert
            expect($result)->toHaveCount(2);
        });

        it('returns empty array when agents are in same team', function () {
            // Arrange
            $message = '@agent-1 @agent-2 please help';

            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Agent::createFromConfig('agent-2', ['name' => 'Agent 2']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'agents' => ['agent-1', 'agent-2'],
                'leader_agent' => 'agent-1',
            ]);

            $agents = $this->settings->getAgents();
            $teams = $this->settings->getTeams();

            // Act
            $result = $this->routingService->detectMultipleAgents($message, $agents, $teams);

            // Assert
            expect($result)->toBeEmpty();
        });

        it('returns empty array for single agent mention', function () {
            // Arrange
            $message = '@agent-1 help me';

            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);

            $agents = $this->settings->getAgents();
            $teams = $this->settings->getTeams();

            // Act
            $result = $this->routingService->detectMultipleAgents($message, $agents, $teams);

            // Assert
            expect($result)->toHaveCount(1);
        });
    });

    describe('parseAgentRouting', function () {
        it('parses explicit agent routing', function () {
            // Arrange
            $message = '@agent-1 Hello there';

            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);

            $agents = $this->settings->getAgents();
            $teams = $this->settings->getTeams();

            // Act
            $result = $this->routingService->parseAgentRouting($message, $agents, $teams);

            // Assert
            expect($result['agentId'])->toBe('agent-1')
                ->and($result['message'])->toBe('Hello there');
        });

        it('parses team routing', function () {
            // Arrange
            $message = '@team-1 Team message';

            Agent::createFromConfig('leader-agent', ['name' => 'Leader']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'leader_agent' => 'leader-agent',
                'agents' => ['leader-agent'],
            ]);

            $agents = $this->settings->getAgents();
            $teams = $this->settings->getTeams();

            // Act
            $result = $this->routingService->parseAgentRouting($message, $agents, $teams);

            // Assert
            expect($result['agentId'])->toBe('leader-agent')
                ->and($result['isTeam'])->toBeTrue()
                ->and($result['teamId'])->toBe('team-1');
        });

        it('returns default for no explicit routing', function () {
            // Arrange
            $message = 'Hello there';
            $agents = new \App\TypedCollections\AgentCollection;
            $teams = new \App\TypedCollections\TeamCollection;

            // Set a default agent
            Agent::createFromConfig('default', ['name' => 'Default Agent']);
            $this->settings->set('agents.default_agent_id', 'default');

            // Act
            $result = $this->routingService->parseAgentRouting($message, $agents, $teams);

            // Assert
            expect($result['agentId'])->toBe('default')
                ->and($result['message'])->toBe('Hello there');
        });

        it('matches agent by name', function () {
            // Arrange
            $message = '@Agent One Hello';

            Agent::createFromConfig('agent-1', ['name' => 'Agent One']);

            $agents = $this->settings->getAgents();
            $teams = $this->settings->getTeams();

            // Act
            $result = $this->routingService->parseAgentRouting($message, $agents, $teams);

            // Assert
            expect($result['agentId'])->toBe('agent-1');
        });
    });

    describe('route', function () {
        it('routes to explicit agent when specified', function () {
            // Arrange
            $message = '@agent-1 Hello';

            // Create a default agent and the target agent
            Agent::createFromConfig('default', ['name' => 'Default Agent']);
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);

            // Set default agent
            $this->settings->set('agents.default_agent_id', 'default');

            // Reload settings to get fresh data
            $this->settings->reload();
            $agents = $this->settings->getAgents();
            $teams = $this->settings->getTeams();

            // Act
            $result = $this->routingService->route($message, $agents, $teams);

            // Assert
            expect($result->agentId)->toBe('agent-1')
                ->and($result->routingMethod)->toBe('explicit');
        });

        it('uses intent classification for routing', function () {
            // Arrange
            $message = 'What is the weather?';

            Agent::createFromConfig('weather-agent', [
                'name' => 'Weather',
                'capabilities' => ['weather'],
            ]);

            $agents = $this->settings->getAgents();
            $teams = $this->settings->getTeams();

            // Act
            $result = $this->routingService->route($message, $agents, $teams);

            // Assert
            expect($result)->toBeInstanceOf(RoutingResultDTO::class)
                ->toHaveKey('agentId')
                ->toHaveKey('routingMethod')
                ->toHaveKey('classification');
        });
    });

    describe('getAgents', function () {
        it('returns all agents', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);

            // Act
            $result = $this->routingService->getAgents();

            // Assert
            expect($result)->toHaveKey('agent-1');
        });
    });

    describe('getTeams', function () {
        it('returns all teams', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'leader_agent' => 'agent-1',
            ]);

            // Act
            $result = $this->routingService->getTeams();

            // Assert
            expect($result)->toHaveKey('team-1');
        });
    });

    describe('getAgent', function () {
        it('returns specific agent', function () {
            // Arrange
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);

            // Act
            $result = $this->routingService->getAgent('agent-1');

            // Assert
            expect($result)->not->toBeNull()
                ->and($result['name'])->toBe('Agent 1');
        });
    });

    describe('getIntentService', function () {
        it('returns intent service', function () {
            // Act
            $result = $this->routingService->getIntentService();

            // Assert
            expect($result)->toBeInstanceOf(IntentClassificationService::class);
        });
    });

    describe('getSkillService', function () {
        it('returns skill service', function () {
            // Act
            $result = $this->routingService->getSkillService();

            // Assert
            expect($result)->toBeInstanceOf(SkillSearchService::class);
        });
    });
});

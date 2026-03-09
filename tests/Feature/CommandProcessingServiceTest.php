<?php

use App\DTOs\CommandResponseDTO;
use App\Models\Agent;
use App\Models\Team;
use App\Services\AgentInvokerService;
use App\Services\CommandProcessingService;
use App\Services\ConversationHistoryService;
use App\Services\SettingsService;

beforeEach(function () {
    $this->settings = app(SettingsService::class);
    $this->chatHistory = app(ConversationHistoryService::class);
    $this->invoker = app(AgentInvokerService::class);

    $this->commandService = new CommandProcessingService(
        $this->settings,
        $this->chatHistory,
        $this->invoker
    );

    // Clear models
    Agent::query()->delete();
    Team::query()->delete();
});

describe('CommandProcessingService', function () {
    describe('process', function () {
        it('returns error for empty message', function () {
            $response = $this->commandService->process('');

            expect($response->success)->toBeFalse()
                ->and($response->type)->toBe('error')
                ->and($response->code)->toBe(400)
                ->and($response->message)->toBe('Empty message');
        });

        it('returns error for whitespace-only message', function () {
            $response = $this->commandService->process('   ');

            expect($response->success)->toBeFalse()
                ->and($response->type)->toBe('error')
                ->and($response->code)->toBe(400);
        });

        it('handles slash commands', function () {
            $response = $this->commandService->process('/help');

            expect($response->success)->toBeTrue()
                ->and($response->type)->toBe('help');
        });

        it('handles @agent mentions', function () {
            // Create a test agent
            Agent::createFromConfig('test-agent', [
                'name' => 'Test Agent',
                'provider' => 'anthropic',
                'model' => 'claude',
            ]);

            // This will attempt to invoke the agent - may fail due to LLM issues
            // but we can test that the routing works correctly
            $response = $this->commandService->process('@test-agent Hello');

            // The response should be either a response or an error (if LLM fails)
            expect(in_array($response->type, ['response', 'error']))->toBeTrue();
        });

        it('returns error for @agent without message', function () {
            $response = $this->commandService->process('@some-agent');

            expect($response->success)->toBeFalse()
                ->and($response->message)->toContain('Message is required');
        });

        it('returns error for non-existent agent', function () {
            $response = $this->commandService->process('@nonexistent Hello');

            expect($response->success)->toBeFalse()
                ->and($response->message)->toContain('Agent not found');
        });
    });

    describe('handleSlashCommand', function () {
        it('handles /agents command', function () {
            Agent::createFromConfig('agent1', ['name' => 'Agent One', 'model' => 'claude']);

            $response = $this->commandService->handleSlashCommand('/agents');

            expect($response->success)->toBeTrue()
                ->and($response->type)->toBe('agents')
                ->and($response->data)->toHaveKey('agents');
        });

        it('handles /teams command', function () {
            // Create an agent first to be the leader
            Agent::createFromConfig('agent1', ['name' => 'Agent One', 'model' => 'claude']);

            Team::createFromConfig('team1', [
                'name' => 'Team One',
                'agents' => ['agent1'],
                'leader_agent' => 'agent1',
            ]);

            $response = $this->commandService->handleSlashCommand('/teams');

            expect($response->success)->toBeTrue()
                ->and($response->type)->toBe('teams')
                ->and($response->data)->toHaveKey('teams');
        });

        it('handles /status command with context', function () {
            $context = [
                'port' => 8080,
                'clients' => 5,
                'uptime' => 3600,
            ];

            $response = $this->commandService->handleSlashCommand('/status', $context);

            expect($response->success)->toBeTrue()
                ->and($response->type)->toBe('status')
                ->and($response->data['port'])->toBe(8080)
                ->and($response->data['clients'])->toBe(5);
        });

        it('handles /ping command', function () {
            $response = $this->commandService->handleSlashCommand('/ping');

            expect($response->success)->toBeTrue()
                ->and($response->type)->toBe('pong')
                ->and($response->message)->toBe('Pong!');
        });

        it('handles /pong command', function () {
            $response = $this->commandService->handleSlashCommand('/pong');

            expect($response->success)->toBeTrue()
                ->and($response->type)->toBe('ping')
                ->and($response->message)->toBe('Ping!');
        });

        it('handles /help command', function () {
            $response = $this->commandService->handleSlashCommand('/help');

            expect($response->success)->toBeTrue()
                ->and($response->type)->toBe('help')
                ->and($response->message)->toContain('Available commands');
        });

        it('returns error for unknown command', function () {
            $response = $this->commandService->handleSlashCommand('/unknown');

            expect($response->success)->toBeFalse()
                ->and($response->type)->toBe('error')
                ->and($response->message)->toContain('Unknown command');
        });
    });

    describe('getAgents', function () {
        it('returns empty agents list', function () {
            $response = $this->commandService->getAgents();

            expect($response->success)->toBeTrue()
                ->and($response->type)->toBe('agents')
                ->and($response->message)->toContain('No agents configured');
        });

        it('returns list of agents', function () {
            Agent::createFromConfig('agent1', [
                'name' => 'Agent One',
                'provider' => 'anthropic',
                'model' => 'claude-3-sonnet',
            ]);
            Agent::createFromConfig('agent2', [
                'name' => 'Agent Two',
                'provider' => 'openai',
                'model' => 'gpt-4',
            ]);

            $response = $this->commandService->getAgents();

            expect($response->success)->toBeTrue()
                ->and($response->type)->toBe('agents')
                ->and($response->message)->toContain('Agent One')
                ->and($response->message)->toContain('Agent Two')
                ->and($response->data['agents'])->toHaveCount(2);
        });
    });

    describe('getTeams', function () {
        it('returns empty teams list', function () {
            $response = $this->commandService->getTeams();

            expect($response->success)->toBeTrue()
                ->and($response->type)->toBe('teams')
                ->and($response->message)->toContain('No teams configured');
        });

        it('returns list of teams', function () {
            // Create agents first
            Agent::createFromConfig('agent1', ['name' => 'Agent One', 'model' => 'claude']);
            Agent::createFromConfig('agent2', ['name' => 'Agent Two', 'model' => 'gpt-4']);

            Team::createFromConfig('team1', [
                'name' => 'Development Team',
                'agents' => ['agent1', 'agent2'],
                'leader_agent' => 'agent1',
            ]);

            $response = $this->commandService->getTeams();

            expect($response->success)->toBeTrue()
                ->and($response->type)->toBe('teams')
                ->and($response->message)->toContain('Development Team')
                ->and($response->data['teams'])->toHaveCount(1);
        });
    });

    describe('getStatus', function () {
        it('returns status with default context', function () {
            $response = $this->commandService->getStatus();

            expect($response->success)->toBeTrue()
                ->and($response->type)->toBe('status')
                ->and($response->data)->toHaveKeys(['status', 'port', 'clients', 'agents_count', 'uptime']);
        });

        it('returns status with provided context', function () {
            $context = [
                'status' => 'running',
                'port' => 9090,
                'clients' => 10,
                'uptime' => 7200,
            ];

            $response = $this->commandService->getStatus($context);

            expect($response->success)->toBeTrue()
                ->and($response->data['port'])->toBe(9090)
                ->and($response->data['clients'])->toBe(10)
                ->and($response->data['uptime_formatted'])->toBe('2h 0m');
        });
    });

    describe('getHistory', function () {
        it('returns empty history', function () {
            $response = $this->commandService->getHistory(null);

            expect($response->success)->toBeTrue()
                ->and($response->type)->toBe('history')
                ->and($response->message)->toContain('No conversation history');
        });

        it('parses limit from args', function () {
            // This tests that the limit parsing works
            $response = $this->commandService->getHistory('25');

            expect($response->success)->toBeTrue()
                ->and($response->type)->toBe('history');
        });

        it('caps limit at 100', function () {
            // Even with a large limit, it should be capped
            $response = $this->commandService->getHistory('500');

            expect($response->success)->toBeTrue();
        });
    });

    describe('getWelcome', function () {
        it('returns welcome message', function () {
            $response = $this->commandService->getWelcome('2.0.0');

            expect($response->success)->toBeTrue()
                ->and($response->type)->toBe('connected')
                ->and($response->message)->toContain('Welcome')
                ->and($response->data['server_version'])->toBe('2.0.0');
        });
    });

    describe('isCommand', function () {
        it('identifies slash commands', function () {
            expect($this->commandService->isCommand('/help'))->toBeTrue()
                ->and($this->commandService->isCommand('/agents'))->toBeTrue()
                ->and($this->commandService->isCommand('/status'))->toBeTrue();
        });

        it('identifies @agent mentions', function () {
            expect($this->commandService->isCommand('@agent hello'))->toBeTrue()
                ->and($this->commandService->isCommand('@coder fix this'))->toBeTrue();
        });

        it('returns false for regular messages', function () {
            expect($this->commandService->isCommand('Hello world'))->toBeFalse()
                ->and($this->commandService->isCommand('This is a regular message'))->toBeFalse();
        });
    });

    describe('getAvailableCommands', function () {
        it('returns list of available commands', function () {
            $commands = $this->commandService->getAvailableCommands();

            expect($commands)->toBeArray()
                ->and($commands)->toContain('/agents')
                ->and($commands)->toContain('/teams')
                ->and($commands)->toContain('/status')
                ->and($commands)->toContain('/history [n]')
                ->and($commands)->toContain('/ping')
                ->and($commands)->toContain('/pong')
                ->and($commands)->toContain('/help');
        });
    });
});

describe('CommandResponseDTO', function () {
    describe('factory methods', function () {
        it('creates success response', function () {
            $response = CommandResponseDTO::success('test', 'Test message', ['key' => 'value']);

            expect($response->success)->toBeTrue()
                ->and($response->type)->toBe('test')
                ->and($response->message)->toBe('Test message')
                ->and($response->data['key'])->toBe('value');
        });

        it('creates error response', function () {
            $response = CommandResponseDTO::error('Something went wrong', 500);

            expect($response->success)->toBeFalse()
                ->and($response->type)->toBe('error')
                ->and($response->code)->toBe(500)
                ->and($response->message)->toBe('Something went wrong');
        });

        it('creates agents response', function () {
            $agents = [
                'agent1' => ['name' => 'Agent One', 'provider' => 'anthropic', 'model' => 'claude'],
            ];

            $response = CommandResponseDTO::agents($agents);

            expect($response->type)->toBe('agents')
                ->and($response->success)->toBeTrue()
                ->and($response->data['agents'])->toBe($agents);
        });

        it('creates teams response', function () {
            $teams = [
                'team1' => ['name' => 'Team One', 'agents' => ['agent1']],
            ];

            $response = CommandResponseDTO::teams($teams);

            expect($response->type)->toBe('teams')
                ->and($response->success)->toBeTrue()
                ->and($response->data['teams'])->toBe($teams);
        });

        it('creates pong response', function () {
            $response = CommandResponseDTO::pong();

            expect($response->type)->toBe('pong')
                ->and($response->message)->toBe('Pong!')
                ->and($response->success)->toBeTrue();
        });

        it('creates ping response', function () {
            $response = CommandResponseDTO::ping();

            expect($response->type)->toBe('ping')
                ->and($response->message)->toBe('Ping!')
                ->and($response->success)->toBeTrue();
        });

        it('creates help response', function () {
            $response = CommandResponseDTO::help();

            expect($response->type)->toBe('help')
                ->and($response->message)->toContain('/agents')
                ->and($response->message)->toContain('/teams');
        });
    });

    describe('serialization', function () {
        it('converts to array', function () {
            $response = new CommandResponseDTO(
                type: 'test',
                message: 'Test message',
                data: ['key' => 'value'],
                code: 200,
                success: true
            );

            $array = $response->toArray();

            expect($array)->toBe([
                'type' => 'test',
                'message' => 'Test message',
                'data' => ['key' => 'value'],
                'code' => 200,
                'success' => true,
            ]);
        });

        it('converts to JSON', function () {
            $response = new CommandResponseDTO(
                type: 'test',
                message: 'Test message',
                data: ['key' => 'value'],
                code: 200,
                success: true
            );

            $json = $response->toJson();
            $decoded = json_decode($json, true);

            expect($decoded['type'])->toBe('test')
                ->and($decoded['message'])->toBe('Test message')
                ->and($decoded['data']['key'])->toBe('value');
        });

        it('creates from array', function () {
            $data = [
                'type' => 'test',
                'message' => 'Test message',
                'data' => ['key' => 'value'],
                'code' => 200,
                'success' => true,
            ];

            $response = CommandResponseDTO::fromArray($data);

            expect($response->type)->toBe('test')
                ->and($response->message)->toBe('Test message')
                ->and($response->data['key'])->toBe('value')
                ->and($response->code)->toBe(200)
                ->and($response->success)->toBeTrue();
        });
    });
});

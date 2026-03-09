<?php

use App\Models\Agent;
use App\Services\HorizonConfigService;
use App\Services\QueueRoutingService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->routingService = new QueueRoutingService;
    $this->settings = app(SettingsService::class);
    $this->service = new HorizonConfigService($this->routingService, $this->settings);

    // Clear agents and cache
    Agent::query()->delete();
    Cache::flush();
});

describe('HorizonConfigService', function () {
    describe('getAllQueues', function () {
        it('returns single queue array for single strategy', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'single']);
            config(['laraclaw.queue.single_queue_name' => 'default']);

            // Act
            $result = $this->service->getAllQueues();

            // Assert
            expect($result)->toBe(['default']);
        });

        it('returns per-agent queues for per_agent strategy', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'per_agent']);
            config(['laraclaw.queue.agent_queue_prefix' => 'agent-']);

            // Create agents in database
            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);
            Agent::createFromConfig('agent-2', ['name' => 'Agent 2']);

            // Act
            $result = $this->service->getAllQueues();

            // Assert
            expect($result)->toBe(['agent-agent-1', 'agent-agent-2']);
        });

        it('returns priority queues for priority strategy', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'priority']);
            config(['laraclaw.queue.agent_queue_prefix' => 'agent-']);

            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);

            // Act
            $result = $this->service->getAllQueues();

            // Assert
            expect($result)->toBeArray();
        });
    });

    describe('getSupervisorConfig', function () {
        it('returns single queue supervisor config', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'single']);
            config(['laraclaw.queue.single_queue_name' => 'default']);
            config(['laraclaw.queue.single_max_processes' => 5]);
            config(['queue.default' => 'redis']);

            // Act
            $result = $this->service->getSupervisorConfig();

            // Assert
            expect($result)->toBeArray()
                ->toHaveKey('supervisor-default')
                ->and($result['supervisor-default']['queue'])->toBe(['default']);
        });

        it('returns per-agent supervisor configs', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'per_agent']);
            config(['laraclaw.queue.agent_queue_prefix' => 'agent-']);
            config(['queue.default' => 'redis']);

            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);

            // Act
            $result = $this->service->getSupervisorConfig();

            // Assert
            expect($result)->toBeArray()
                ->toHaveKey('supervisor-agent-1');
        });

        it('returns priority supervisor configs', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'priority']);
            config(['laraclaw.queue.agent_queue_prefix' => 'agent-']);
            config(['laraclaw.queue.priority_tiers' => [
                'high' => [
                    'agents' => ['agent-1'],
                    'processes_per_agent' => 1,
                    'max_processes' => 5,
                ],
            ]]);
            config(['queue.default' => 'redis']);

            Agent::createFromConfig('agent-1', ['name' => 'Agent 1']);

            // Act
            $result = $this->service->getSupervisorConfig();

            // Assert
            expect($result)->toBeArray();
        });
    });

    describe('generateConfigFile', function () {
        it('generates valid PHP config content', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'single']);
            config(['laraclaw.queue.single_queue_name' => 'default']);

            // Act
            $result = $this->service->generateConfigFile('production');

            // Assert
            expect($result)->toBeString()
                ->toContain('<?php')
                ->toContain("'environments'")
                ->toContain("'production'");
        });
    });

    describe('strategy constants', function () {
        it('has correct constant values', function () {
            expect(HorizonConfigService::STRATEGY_SINGLE)->toBe('single')
                ->and(HorizonConfigService::STRATEGY_PER_AGENT)->toBe('per_agent')
                ->and(HorizonConfigService::STRATEGY_PRIORITY)->toBe('priority');
        });
    });
});

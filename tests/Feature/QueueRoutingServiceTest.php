<?php

use App\Models\Agent;
use App\Services\QueueRoutingService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->settings = app(SettingsService::class);
    $this->service = new QueueRoutingService;

    // Clear agents and cache
    Agent::query()->delete();
    Cache::flush();
});

describe('QueueRoutingService', function () {
    describe('getQueueForAgent', function () {
        it('returns single queue for single strategy', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'single']);
            config(['laraclaw.queue.single_queue_name' => 'default']);

            // Act
            $result = $this->service->getQueueForAgent('agent-1');

            // Assert
            expect($result)->toBe('default');
        });

        it('returns per-agent queue for per_agent strategy', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'per_agent']);
            config(['laraclaw.queue.agent_queue_prefix' => 'agent-']);

            // Act
            $result = $this->service->getQueueForAgent('my-agent');

            // Assert
            expect($result)->toBe('agent-my-agent');
        });

        it('returns priority queue for priority strategy', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'priority']);
            config(['laraclaw.queue.agent_queue_prefix' => 'agent-']);
            config(['laraclaw.queue.priority_tiers' => [
                'high' => ['agents' => ['priority-agent']],
                'default' => ['agents' => ['*']],
            ]]);

            // Act
            $result = $this->service->getQueueForAgent('some-agent');

            // Assert
            expect($result)->toStartWith('agent-');
        });

        it('handles null agent id', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'per_agent']);
            config(['laraclaw.queue.agent_queue_prefix' => 'agent-']);

            // Act
            $result = $this->service->getQueueForAgent(null);

            // Assert
            expect($result)->toBe('agent-default');
        });

        it('defaults to single strategy for unknown strategy', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'unknown']);
            config(['laraclaw.queue.single_queue_name' => 'default']);

            // Act
            $result = $this->service->getQueueForAgent('agent-1');

            // Assert
            expect($result)->toBe('default');
        });
    });

    describe('guaranteesOrdering', function () {
        it('returns false for single strategy', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'single']);

            // Act
            $result = $this->service->guaranteesOrdering();

            // Assert
            expect($result)->toBeFalse();
        });

        it('returns true for per_agent strategy', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'per_agent']);

            // Act
            $result = $this->service->guaranteesOrdering();

            // Assert
            expect($result)->toBeTrue();
        });

        it('returns true for priority strategy', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'priority']);

            // Act
            $result = $this->service->guaranteesOrdering();

            // Assert
            expect($result)->toBeTrue();
        });
    });

    describe('getStrategyDescription', function () {
        it('returns description for single strategy', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'single']);

            // Act
            $result = $this->service->getStrategyDescription();

            // Assert
            expect($result)->toBeString()
                ->toContain('Single queue');
        });

        it('returns description for per_agent strategy', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'per_agent']);

            // Act
            $result = $this->service->getStrategyDescription();

            // Assert
            expect($result)->toBeString()
                ->toContain('Per-agent queues');
        });

        it('returns description for priority strategy', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'priority']);

            // Act
            $result = $this->service->getStrategyDescription();

            // Assert
            expect($result)->toBeString()
                ->toContain('Priority queues');
        });

        it('returns unknown description for unknown strategy', function () {
            // Arrange
            config(['laraclaw.queue.strategy' => 'unknown']);

            // Act
            $result = $this->service->getStrategyDescription();

            // Assert
            expect($result)->toBeString()
                ->toContain('Unknown');
        });
    });

    describe('strategy constants', function () {
        it('has correct constant values', function () {
            expect(QueueRoutingService::STRATEGY_SINGLE)->toBe('single')
                ->and(QueueRoutingService::STRATEGY_PER_AGENT)->toBe('per_agent')
                ->and(QueueRoutingService::STRATEGY_PRIORITY)->toBe('priority');
        });
    });
});

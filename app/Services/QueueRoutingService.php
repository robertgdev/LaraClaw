<?php

namespace App\Services;

use App\Logging\MultiLogger;

/**
 * QueueRoutingService - Determines which queue a message should be dispatched to.
 *
 * This service implements configurable queue routing strategies:
 * - single: All messages go to a single queue (simple, no ordering guarantee)
 * - per_agent: Each agent gets its own queue (ordering guaranteed, more complex)
 * - priority: Agents are grouped into priority tiers with per-agent queues
 *
 * IMPORTANT: Ordering is only guaranteed when each queue is serviced by exactly
 * one worker. Multiple workers on the same queue can process messages out of order.
 *
 * For Horizon supervisor configuration generation, see HorizonConfigService.
 *
 * @see \App\Services\HorizonConfigService
 */
class QueueRoutingService
{
    /**
     * Available queue strategies.
     */
    public const STRATEGY_SINGLE = 'single';

    public const STRATEGY_PER_AGENT = 'per_agent';

    public const STRATEGY_PRIORITY = 'priority';

    /**
     * Get the queue name for a given agent.
     *
     * @param  string|null  $agentId  The agent ID to route
     * @return string The queue name
     */
    public function getQueueForAgent(?string $agentId): string
    {
        $strategy = config('laraclaw.queue.strategy', self::STRATEGY_SINGLE);
        $agentId = $agentId ?? 'default';

        return match ($strategy) {
            self::STRATEGY_PER_AGENT => $this->getPerAgentQueue($agentId),
            self::STRATEGY_PRIORITY => $this->getPriorityQueue($agentId),
            default => $this->getSingleQueue(),
        };
    }

    /**
     * Get the single queue name (all messages to one queue).
     */
    protected function getSingleQueue(): string
    {
        return config('laraclaw.queue.single_queue_name', 'default');
    }

    /**
     * Get the per-agent queue name.
     *
     * Format: agent-{agent_id}
     */
    protected function getPerAgentQueue(string $agentId): string
    {
        $prefix = config('laraclaw.queue.agent_queue_prefix', 'agent-');

        return $prefix.$agentId;
    }

    /**
     * Get the priority queue for an agent.
     *
     * Agents are mapped to priority tiers. Each agent still gets its own
     * queue for ordering guarantees, but queues are organized by priority.
     */
    protected function getPriorityQueue(string $agentId): string
    {
        $priorityTiers = config('laraclaw.queue.priority_tiers', []);
        $prefix = config('laraclaw.queue.agent_queue_prefix', 'agent-');

        // Find which priority tier this agent belongs to
        foreach ($priorityTiers as $tierName => $tierConfig) {
            $agents = $tierConfig['agents'] ?? [];

            // Check if agent is in this tier
            if (in_array($agentId, $agents) || in_array('*', $agents)) {
                // Log the priority assignment for debugging
                MultiLogger::debug("Agent {$agentId} assigned to priority tier: {$tierName}");

                // Return per-agent queue (for ordering guarantee)
                return $prefix.$agentId;
            }
        }

        // Fallback to default queue
        return $prefix.$agentId;
    }

    /**
     * Check if the current strategy guarantees ordering.
     */
    public function guaranteesOrdering(): bool
    {
        $strategy = config('laraclaw.queue.strategy', self::STRATEGY_SINGLE);

        // Single queue with multiple processes does NOT guarantee ordering
        // Per-agent and priority strategies DO guarantee ordering (with 1 worker per queue)
        return $strategy !== self::STRATEGY_SINGLE;
    }

    /**
     * Get a human-readable description of the current queue strategy.
     */
    public function getStrategyDescription(): string
    {
        $strategy = config('laraclaw.queue.strategy', self::STRATEGY_SINGLE);

        return match ($strategy) {
            self::STRATEGY_SINGLE => 'Single queue - all messages processed by a shared worker pool. '.
                'No per-agent ordering guarantee. Simple and efficient for low-volume setups.',

            self::STRATEGY_PER_AGENT => 'Per-agent queues - each agent has its own queue with a single worker. '.
                'Guarantees in-order processing per agent. Best for high-volume multi-user scenarios.',

            self::STRATEGY_PRIORITY => 'Priority queues - agents grouped into priority tiers. '.
                'Each agent still has its own queue for ordering guarantee. '.
                'Balances performance with ordering guarantees.',

            default => 'Unknown queue strategy.',
        };
    }
}

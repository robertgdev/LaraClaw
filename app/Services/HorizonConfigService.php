<?php

namespace App\Services;

/**
 * HorizonConfigService - Generates Horizon supervisor configurations.
 *
 * This service generates dynamic Horizon supervisor configurations based on
 * the queue strategy and agents in the database. Useful for:
 * - Deployment scripts that generate horizon.php
 * - Setup commands
 * - Auto-scaling scenarios
 *
 * This service is optional and can be used when dynamic Horizon configuration
 * is needed. The core queue routing logic remains in QueueRoutingService.
 */
class HorizonConfigService
{
    /**
     * Queue strategy constants (mirrored from QueueRoutingService).
     */
    public const STRATEGY_SINGLE = 'single';

    public const STRATEGY_PER_AGENT = 'per_agent';

    public const STRATEGY_PRIORITY = 'priority';

    /**
     * Create a new HorizonConfigService instance.
     */
    public function __construct(
        protected QueueRoutingService $routingService,
        protected SettingsService $settings
    ) {}

    /**
     * Get all queue names that should be configured in Horizon.
     *
     * This is useful for generating Horizon configuration dynamically.
     *
     * @return array<string> List of queue names
     */
    public function getAllQueues(): array
    {
        $strategy = config('laraclaw.queue.strategy', self::STRATEGY_SINGLE);
        $agents = $this->settings->getAgents();

        return match ($strategy) {
            self::STRATEGY_SINGLE => [$this->getSingleQueueName()],

            self::STRATEGY_PER_AGENT => array_map(
                fn ($agentId) => $this->routingService->getQueueForAgent($agentId),
                $agents->keys()->toArray()
            ),

            self::STRATEGY_PRIORITY => array_map(
                fn ($agentId) => $this->routingService->getQueueForAgent($agentId),
                $agents->keys()->toArray()
            ),

            default => [$this->getSingleQueueName()],
        };
    }

    /**
     * Get Horizon supervisor configuration based on strategy.
     *
     * Returns an array suitable for config/horizon.php environments.
     *
     * @return array<string, array{connection: string, queue: array<int, string>, balance: string, maxProcesses?: int, minProcesses?: int, processes?: int, tries: int, timeout: int, retryAfter: int}>
     */
    public function getSupervisorConfig(): array
    {
        $strategy = config('laraclaw.queue.strategy', self::STRATEGY_SINGLE);
        $connection = config('queue.default', 'redis');

        return match ($strategy) {
            self::STRATEGY_SINGLE => $this->getSingleQueueSupervisor($connection),
            self::STRATEGY_PER_AGENT => $this->getPerAgentSupervisors($connection),
            self::STRATEGY_PRIORITY => $this->getPrioritySupervisors($connection),
            default => $this->getSingleQueueSupervisor($connection),
        };
    }

    /**
     * Get the single queue name from config.
     */
    protected function getSingleQueueName(): string
    {
        return config('laraclaw.queue.single_queue_name', 'default');
    }

    /**
     * Get supervisor config for single queue strategy.
     *
     * @return array<string, array{connection: string, queue: array<int, string>, balance: string, maxProcesses: int, minProcesses: int, tries: int, timeout: int, retryAfter: int}>
     */
    protected function getSingleQueueSupervisor(string $connection): array
    {
        $maxProcesses = config('laraclaw.queue.single_max_processes', 5);

        return [
            'supervisor-default' => [
                'connection' => $connection,
                'queue' => [$this->getSingleQueueName()],
                'balance' => 'auto',
                'maxProcesses' => $maxProcesses,
                'minProcesses' => 1,
                'tries' => 3,
                'timeout' => 300, // 5 minutes for AI responses
                'retryAfter' => 60,
            ],
        ];
    }

    /**
     * Get supervisor configs for per-agent strategy.
     *
     * Each agent gets its own supervisor with 1 process for ordering guarantee.
     *
     * @return array<string, array{connection: string, queue: array<int, string>, balance: string, processes: int, tries: int, timeout: int, retryAfter: int}>
     */
    protected function getPerAgentSupervisors(string $connection): array
    {
        $agents = $this->settings->getAgents();
        $supervisors = [];

        foreach ($agents as $agentId => $agentConfig) {
            $queueName = $this->routingService->getQueueForAgent($agentId);

            $supervisors["supervisor-{$agentId}"] = [
                'connection' => $connection,
                'queue' => [$queueName],
                'balance' => 'simple',
                'processes' => 1, // Single process for ordering guarantee
                'tries' => 3,
                'timeout' => 300,
                'retryAfter' => 60,
            ];
        }

        return $supervisors;
    }

    /**
     * Get supervisor configs for priority strategy.
     *
     * Agents are grouped by priority tier. Each agent still gets 1 process
     * for ordering guarantee, but supervisors are organized by tier.
     *
     * @return array<string, array{connection: string, queue: array<int, string>, balance: string, maxProcesses?: int, minProcesses?: int, processes?: int, tries: int, timeout: int, retryAfter: int}>
     */
    protected function getPrioritySupervisors(string $connection): array
    {
        $agents = $this->settings->getAgents();
        $priorityTiers = config('laraclaw.queue.priority_tiers', []);
        $supervisors = [];

        // Create supervisors for each priority tier
        foreach ($priorityTiers as $tierName => $tierConfig) {
            $tierAgents = $tierConfig['agents'] ?? [];
            $maxTierProcesses = $tierConfig['max_processes'] ?? 10;

            // Resolve agent IDs from tier config
            $resolvedAgents = [];
            foreach ($tierAgents as $agentSpecifier) {
                if ($agentSpecifier === '*') {
                    // Wildcard: all agents not explicitly in other tiers
                    $resolvedAgents = array_keys($agents->toArray());
                    break;
                }
                if ($agents->has($agentSpecifier)) {
                    $resolvedAgents[] = $agentSpecifier;
                }
            }

            // Create queue names for this tier
            $queues = array_map(
                fn ($agentId) => $this->routingService->getQueueForAgent($agentId),
                array_unique($resolvedAgents)
            );

            if (empty($queues)) {
                continue;
            }

            $supervisors["supervisor-{$tierName}"] = [
                'connection' => $connection,
                'queue' => $queues,
                'balance' => 'auto',
                'maxProcesses' => $maxTierProcesses,
                'minProcesses' => 1,
                'processes' => count($queues), // 1 process per queue for ordering
                'tries' => 3,
                'timeout' => 300,
                'retryAfter' => 60,
            ];
        }

        // Ensure we have at least a default supervisor
        if (empty($supervisors)) {
            return $this->getSingleQueueSupervisor($connection);
        }

        return $supervisors;
    }

    /**
     * Generate a PHP config file content for horizon.php.
     *
     * This can be used to generate a horizon.php file dynamically.
     *
     * @param  string  $environment  The environment name (e.g., 'production', 'local')
     * @return string PHP config file content
     */
    public function generateConfigFile(string $environment = 'production'): string
    {
        $supervisors = $this->getSupervisorConfig();

        $content = "<?php\n\n";
        $content .= "// Auto-generated Horizon configuration for LaraClaw\n";
        $content .= '// Generated at: '.date('Y-m-d H:i:s')."\n\n";
        $content .= "return [\n";
        $content .= "    'environments' => [\n";
        $content .= "        '{$environment}' => [\n";

        foreach ($supervisors as $name => $config) {
            $content .= "            '{$name}' => [\n";
            foreach ($config as $key => $value) {
                $exportedValue = is_array($value)
                    ? "['".implode("', '", $value)."']"
                    : var_export($value, true);
                $content .= "                '{$key}' => {$exportedValue},\n";
            }
            $content .= "            ],\n";
        }

        $content .= "        ],\n";
        $content .= "    ],\n";
        $content .= "];\n";

        return $content;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Commands;

use App\DTOs\CommandResponseDTO;
use App\Logging\MultiLogger;
use App\Services\ConversationHistoryService;
use App\Services\SettingsService;

/**
 * Handles slash commands (e.g. /agents, /teams, /status, /history).
 *
 * Extracted from CommandProcessingService for single-responsibility.
 */
class SlashCommandHandler
{
    public function __construct(
        protected SettingsService $settings,
        protected ConversationHistoryService $chatHistoryService
    ) {}

    /**
     * Handle slash commands.
     */
    public function handle(string $message, array $context = []): CommandResponseDTO
    {
        $parts = preg_split('/\s+/', $message, 2);
        $command = strtolower($parts[0]);
        $args = $parts[1] ?? null;

        MultiLogger::info("Processing slash command: {$command}");

        return match ($command) {
            '/agents', '/agent' => $this->getAgents(),
            '/teams', '/team' => $this->getTeams(),
            '/status' => $this->getStatus($context),
            '/history' => $this->getHistory($args),
            '/ping' => CommandResponseDTO::pong(),
            '/pong' => CommandResponseDTO::ping(),
            '/help' => CommandResponseDTO::help(),
            '/reset' => $this->handleResetCommand($args),
            default => CommandResponseDTO::error(
                "Unknown command: {$command}. Type /help for available commands."
            ),
        };
    }

    /**
     * Handle reset command for agents.
     */
    public function handleResetCommand(?string $args): CommandResponseDTO
    {
        if (empty($args)) {
            return new CommandResponseDTO(
                type: 'reset_usage',
                message: "Usage: /reset @agent_id [@agent_id2 ...]\nSpecify which agent(s) to reset.",
                data: [],
                code: 200,
                success: true
            );
        }

        $agentIds = array_map(
            fn ($a) => strtolower(ltrim(trim($a), '@')),
            preg_split('/\s+/', $args)
        );

        $agents = $this->settings->getAgents();
        $workspacePath = $this->settings->getWorkspacePath();
        $results = [];

        foreach ($agentIds as $agentId) {
            if (! isset($agents[$agentId])) {
                $results[] = "Agent '{$agentId}' not found.";

                continue;
            }

            $flagDir = $workspacePath.'/'.$agentId;
            if (! \Illuminate\Support\Facades\File::isDirectory($flagDir)) {
                \Illuminate\Support\Facades\File::makeDirectory($flagDir, 0755, true);
            }

            \Illuminate\Support\Facades\File::put($flagDir.'/reset_flag', 'reset');
            $results[] = "Reset @{$agentId} ({$agents[$agentId]['name']}).";
        }

        return new CommandResponseDTO(
            type: 'reset',
            message: implode("\n", $results),
            data: ['reset_agents' => $agentIds, 'results' => $results],
            code: 200,
            success: true
        );
    }

    /**
     * Get list of all agents.
     */
    public function getAgents(): CommandResponseDTO
    {
        $agents = $this->settings->getAgents();

        return CommandResponseDTO::agents($agents->toArray());
    }

    /**
     * Get list of all teams.
     */
    public function getTeams(): CommandResponseDTO
    {
        $teams = $this->settings->getTeams();

        return CommandResponseDTO::teams($teams->toArray());
    }

    /**
     * Get server status.
     */
    public function getStatus(array $context = []): CommandResponseDTO
    {
        $agents = $this->settings->getAgents();
        $uptime = $context['uptime'] ?? 0;

        $statusData = [
            'status' => $context['status'] ?? 'running',
            'port' => $context['port'] ?? 0,
            'clients' => $context['clients'] ?? 0,
            'agents_count' => $agents->count(),
            'uptime' => $uptime,
            'uptime_formatted' => $this->formatUptime($uptime),
        ];

        return CommandResponseDTO::status($statusData);
    }

    /**
     * Get conversation history.
     */
    public function getHistory(?string $args): CommandResponseDTO
    {
        $limit = 10;

        if ($args && is_numeric(trim($args))) {
            $limit = min((int) trim($args), 100);
        }

        $history = $this->chatHistoryService->getRecentHistory($limit);

        $displayHistory = array_map(function ($entry) {
            return [
                'timestamp' => $entry['date'] ?? 'unknown',
                'from' => $entry['sender'] ?? 'unknown',
                'to' => $entry['team_id'] ?? 'agent',
                'message' => $entry['preview'] ?? '',
            ];
        }, $history);

        return CommandResponseDTO::history($displayHistory, $limit);
    }

    /**
     * Format uptime in human-readable format.
     */
    protected function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($days > 0) {
            return sprintf('%dd %dh %dm', $days, $hours, $minutes);
        }
        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm', $minutes);
    }
}

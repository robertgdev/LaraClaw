<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\Team;
use function Safe\json_encode;

/**
 * Data Transfer Object for command processing responses.
 * Provides a generic, JSON-serializable response format that can be used
 * across different transport layers (WebSocket, HTTP, CLI).
 */
final class CommandResponseDTO
{
    /**
     * @param  string  $type  Response type (e.g., 'agents', 'teams', 'status', 'error', 'response')
     * @param  string  $message  Human-readable message
     * @param  array<string, mixed>  $data  Structured data payload
     * @param  int  $code  HTTP-style status code
     * @param  bool  $success  Whether the command was successful
     */
    public function __construct(
        public string $type,
        public string $message,
        public array $data = [],
        public int $code = 200,
        public bool $success = true,
    ) {}

    /**
     * Create a successful response.
     *
     * @param  array<mixed>  $data
     */
    public static function success(string $type, string $message, array $data = [], int $code = 200): self
    {
        return new self(type: $type, message: $message, data: $data, code: $code, success: true);
    }

    /**
     * Create an error response.
     *
     * @param  array<mixed>  $data
     */
    public static function error(string $message, int $code = 400, string $type = 'error', array $data = []): self
    {
        return new self(type: $type, message: $message, data: $data, code: $code, success: false);
    }

    /**
     * Create a response for agent list.
     *
     * @param  array<string, array<string, mixed>>  $agents
     */
    public static function agents(array $agents): self
    {
        $lines = ["Available agents:\n"];

        foreach ($agents as $id => $agent) {
            $provider = $agent['provider'] ?? 'unknown';
            $model = $agent['model'] ?? 'unknown';
            $lines[] = sprintf('  • %s (%s) - %s/%s', $id, $agent['name'] ?? $id, $provider, $model);
        }

        if (empty($agents)) {
            $lines[] = '  No agents configured.';
        }
        $lines = implode("\n", $lines);

        return new self(type: 'agents', message: $lines, data: ['agents' => $agents], code: 200, success: true);
    }

    /**
     * Create a response for team list.
     *
     * @param  array<string, array<string, mixed>|Team>  $teams
     */
    public static function teams(array $teams): self
    {
        $lines = ["Available teams:\n"];

        foreach ($teams as $id => $team) {
            // Handle both array and Team model
            if (is_object($team) && method_exists($team, 'toConfigArray')) {
                $teamData = $team->toConfigArray();
            } else {
                $teamData = is_array($team) ? $team : [];
            }

            $leader = $teamData['leader_agent'] ?? $teamData['leader_agent_id'] ?? 'none';
            $agentsList = $teamData['agents'] ?? [];

            // Handle agents that might be arrays of Agent models, arrays, or strings
            if (is_array($agentsList)) {
                $agentIds = array_map(function ($agent) {
                    if (is_object($agent)) {
                        return $agent->agent_id ?? $agent->id ?? 'unknown';
                    }
                    if (is_array($agent)) {
                        return $agent['agent_id'] ?? $agent['id'] ?? json_encode($agent);
                    }

                    return (string) $agent;
                }, $agentsList);
                $agents = implode(', ', $agentIds);
            } else {
                $agents = 'none';
            }

            $lines[] = sprintf(
                '  • %s (%s) - Leader: %s, Agents: %s',
                $id,
                $teamData['name'] ?? $id,
                $leader,
                $agents ?: 'none'
            );
        }

        if (empty($teams)) {
            $lines[] = '  No teams configured.';
        }

        // Convert teams to arrays for data
        $teamsArray = [];
        /** @var Team $team */
        foreach ($teams as $id => $team) {
            if (is_object($team) && method_exists($team, 'toConfigArray')) {
                $teamsArray[$id] = $team->toConfigArray();
            } else {
                $teamsArray[$id] = is_array($team) ? $team : [];
            }
        }
        $lines = implode("\n", $lines);

        return new self(type: 'teams', message: $lines, data: ['teams' => $teamsArray], code: 200, success: true);
    }

    /**
     * Create a response for server status.
     *
     * @param  array<string, mixed>  $statusData
     */
    public static function status(array $statusData): self
    {
        $status = sprintf(
            "Server Status:\n  Status: %s\n  Port: %d\n  Clients: %d\n  Agents: %d\n  Uptime: %s",
            $statusData['status'] ?? 'unknown',
            $statusData['port'] ?? 0,
            $statusData['clients'] ?? 0,
            $statusData['agents_count'] ?? 0,
            $statusData['uptime_formatted'] ?? '0m'
        );

        return new self(type: 'status', message: $status, data: $statusData, code: 200, success: true);
    }

    /**
     * Create a response for conversation history.
     *
     * @param  array<int, array<string, mixed>>  $history
     */
    public static function history(array $history, int $limit): self
    {
        if (empty($history)) {
            $message = 'No conversation history found.';

            return new self(type: 'history', message: $message, data: ['history' => []], code: 200, success: true);
        }

        $lines = ["Recent conversation history (last {$limit}):\n"];

        foreach ($history as $entry) {
            $time = $entry['timestamp'] ?? 'unknown';
            $from = $entry['from'] ?? 'unknown';
            $to = $entry['to'] ?? 'unknown';
            $msg = substr($entry['message'] ?? '', 0, 100);
            $lines[] = sprintf('  [%s] %s → %s: %s', $time, $from, $to, $msg);
        }
        $lines = implode("\n", $lines);

        return new self(type: 'history', message: $lines, data: ['history' => $history], code: 200, success: true);
    }

    /**
     * Create a response for agent invocation.
     */
    public static function agentResponse(
        string $agentId,
        string $agentName,
        string $response,
        ?string $provider = null,
        ?string $model = null,
        ?string $conversationId = null
    ): self {
        $data = [
            'agent_id' => $agentId,
            'agent_name' => $agentName,
            'provider' => $provider,
            'model' => $model,
            'conversation_id' => $conversationId,
        ];

        return new self(type: 'response', message: $response, data: $data, code: 200, success: true);
    }

    /**
     * Create a pong response.
     */
    public static function pong(): self
    {
        return new self(type: 'pong', message: 'Pong!', data: [], code: 200, success: true);
    }

    /**
     * Create a ping response.
     */
    public static function ping(): self
    {
        return new self(type: 'ping', message: 'Ping!', data: [], code: 200, success: true);
    }

    /**
     * Create a help response.
     */
    public static function help(): self
    {
        $help = <<<'HELP'
Available commands:

  /agents          List all agents
  /teams           List all teams
  /status          Get server status
  /history [n]     Get last n messages (default: 10)
  /ping            Ping the server
  /pong            Pong response
  /help            Show this help

To send a message:
  - Type any text to send to the default agent
  - Use @agent_id message to send to a specific agent

Examples:
  Hello, how are you?           → Sends to default agent
  @coder Fix the bug in auth    → Sends to agent "coder"
HELP;

        return new self(type: 'help', message: $help, data: [], code: 200, success: true);
    }

    /**
     * Create a connected/welcome response.
     */
    public static function connected(string $serverVersion = '1.0.0'): self
    {
        return new self(
            type: 'connected',
            message: 'Welcome to LaraClaw WebSocket Server',
            data: ['server_version' => $serverVersion],
            code: 200,
            success: true
        );
    }

    /**
     * Convert to array for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'message' => $this->message,
            'data' => $this->data,
            'code' => $this->code,
            'success' => $this->success,
        ];
    }

    /**
     * Convert to JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }

    /**
     * Create from array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? 'unknown',
            message: $data['message'] ?? '',
            data: $data['data'] ?? [],
            code: $data['code'] ?? 200,
            success: $data['success'] ?? true
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\CommandResponseDTO;
use App\DTOs\RoutingResultDTO;
use App\Enums\ChannelEnum;
use App\Logging\MultiLogger;
use App\Services\Commands\ChannelCommandHandler;
use App\Services\Commands\JsonMessageHandler;
use App\Services\Commands\SlashCommandHandler;
use App\Services\Conversation\ConversationLifecycleService;

/**
 * Command Processing Service - Generic command parsing and processing.
 *
 * This service provides a transport-agnostic way to process commands and messages.
 * It returns CommandResponseDTO objects that can be serialized to JSON and used
 * across different transport layers (WebSocket, HTTP, CLI).
 *
 * Supported commands:
 *   /agents - List all agents
 *   /teams - List all teams
 *   /status - Get server status
 *   /history [n] - Get conversation history
 *   /ping - Ping the server
 *   /pong - Pong response
 *   /help - Show help
 *
 *   @agent_id message - Send message to specific agent
 *   message - Send message to default agent
 */
class CommandProcessingService
{
    protected SettingsService $settings;

    protected ConversationHistoryService $chatHistoryService;

    protected AgentInvokerService $invokerService;

    protected ?RoutingService $routingService = null;

    protected ?MemoryEngineService $memoryService = null;

    protected ConversationLifecycleService $lifecycleService;

    protected SlashCommandHandler $slashHandler;

    protected ChannelCommandHandler $channelHandler;

    protected JsonMessageHandler $jsonHandler;

    public function __construct(
        SettingsService $settings,
        ConversationHistoryService $chatHistoryService,
        AgentInvokerService $invokerService
    ) {
        $this->settings = $settings;
        $this->chatHistoryService = $chatHistoryService;
        $this->invokerService = $invokerService;
        $this->lifecycleService = new ConversationLifecycleService;
        $this->slashHandler = new SlashCommandHandler($settings, $chatHistoryService);
        $this->channelHandler = new ChannelCommandHandler($this->slashHandler);
        $this->jsonHandler = new JsonMessageHandler($chatHistoryService);
    }

    /**
     * Set the memory service for episodic memory recording.
     */
    public function setMemoryService(MemoryEngineService $memoryService): self
    {
        $this->memoryService = $memoryService;
        $this->lifecycleService->setMemoryService($memoryService);

        return $this;
    }

    /**
     * Set the routing service (optional, for CLI-style intelligent routing).
     */
    public function setRoutingService(RoutingService $routingService): self
    {
        $this->routingService = $routingService;

        return $this;
    }

    /**
     * Process a message and return a response.
     *
     * @param  string  $message  The raw message to process
     * @param  array<string, mixed>  $context  Optional context (e.g., server status, client info)
     * @return CommandResponseDTO The response
     */
    public function process(string $message, array $context = []): CommandResponseDTO
    {
        $message = trim($message);

        if (empty($message)) {
            return CommandResponseDTO::error('Empty message', 400);
        }

        try {
            // Check for JSON message (from web client)
            if (str_starts_with($message, '{')) {
                return $this->handleJsonMessage($message, $context);
            }

            // Check for slash commands
            if (str_starts_with($message, '/')) {
                return $this->handleSlashCommand($message, $context);
            }

            // Check for @agent mention
            if (preg_match('/^@([a-zA-Z0-9_-]+)\s*(.*)$/s', $message, $matches)) {
                $agentId = $matches[1];
                $agentMessage = trim($matches[2]);

                if (empty($agentMessage)) {
                    return CommandResponseDTO::error(
                        'Message is required when mentioning an agent. Usage: @agent_id your message'
                    );
                }

                return $this->sendMessageToAgent($agentMessage, $agentId);
            }

            // Default: send to default agent
            return $this->sendMessageToAgent($message);

        } catch (\Exception $e) {
            MultiLogger::error("Command processing error: {$e->getMessage()}");

            return CommandResponseDTO::error($e->getMessage(), 500);
        }
    }

    /**
     * Handle JSON messages from the web client.
     *
     * Delegates to JsonMessageHandler for all JSON message processing.
     *
     * @param  string  $message  The JSON message
     * @param  array<string, mixed>  $context  Optional context
     * @return CommandResponseDTO The response
     */
    protected function handleJsonMessage(string $message, array $context = []): CommandResponseDTO
    {
        $data = json_decode($message, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return CommandResponseDTO::error('Invalid JSON: '.json_last_error_msg(), 400);
        }

        return $this->jsonHandler->handle(
            $data,
            $context,
            fn (string $msg, ?string $agentId, ?string $convId) => $this->sendMessageToAgent($msg, $agentId, $convId)
        );
    }

    /**
     * Handle slash commands.
     *
     * @param  string  $message  The command message
     * @param  array<string, mixed>  $context  Optional context
     * @return CommandResponseDTO The response
     */
    public function handleSlashCommand(string $message, array $context = []): CommandResponseDTO
    {
        return $this->slashHandler->handle($message, $context);
    }

    /**
     * Handle reset command for agents.
     *
     * @param  string|null  $args  Agent IDs to reset (space-separated, optional @ prefix)
     */
    public function handleResetCommand(?string $args): CommandResponseDTO
    {
        return $this->slashHandler->handleResetCommand($args);
    }

    /**
     * Get list of all agents.
     */
    public function getAgents(): CommandResponseDTO
    {
        return $this->slashHandler->getAgents();
    }

    /**
     * Get list of all teams. Delegates to SlashCommandHandler.
     */
    public function getTeams(): CommandResponseDTO
    {
        return $this->slashHandler->getTeams();
    }

    /**
     * Get server status. Delegates to SlashCommandHandler.
     */
    public function getStatus(array $context = []): CommandResponseDTO
    {
        return $this->slashHandler->getStatus($context);
    }

    /**
     * Get conversation history. Delegates to SlashCommandHandler.
     */
    public function getHistory(?string $args): CommandResponseDTO
    {
        return $this->slashHandler->getHistory($args);
    }

    /**
     * Send a message to an agent.
     *
     * @param  string  $message  The message to send
     * @param  string|null  $agentId  The agent ID (null for default agent)
     * @param  string|null  $conversationId  The conversation ID (for continuing a conversation)
     */
    public function sendMessageToAgent(string $message, ?string $agentId = null, ?string $conversationId = null): CommandResponseDTO
    {
        // Use default agent if not specified
        if ($agentId === null) {
            $agentId = $this->settings->getDefaultAgentId();
        }

        // Check if we have a default agent
        if ($agentId === null) {
            return CommandResponseDTO::error(
                'No default agent configured. Use /agents to list available agents or @agent_id to specify one.'
            );
        }

        // Get agents and teams
        $agents = $this->settings->getAgents();
        $teams = $this->settings->getTeams();

        // Verify agent exists
        if (! isset($agents[$agentId])) {
            return CommandResponseDTO::error(
                "Agent not found: @{$agentId}. Use /agents to list available agents."
            );
        }

        $agent = $agents[$agentId];

        MultiLogger::info("Sending message to agent: {$agentId}");

        // Invoke the agent
        $response = $this->invokerService->invokeAgent(
            $agent,
            $agentId,
            $message,
            false, // reset
            $agents,
            $teams
        );

        // Use ConversationLifecycleService for conversation management
        $conversation = $this->lifecycleService->findOrCreate(
            $conversationId,
            ChannelEnum::WEBSOCKET,
            'user',
            null,
            $message
        );

        // Record exchange (user message + agent response + metadata)
        $this->lifecycleService->recordExchange(
            $conversation,
            $message,
            $agentId,
            $agent->name ?? $agentId,
            $response,
            $agent->provider ?? null,
            $agent->model ?? null
        );

        // Record episodic memory (fire-and-forget)
        $this->lifecycleService->recordMemory(
            $conversation->sender_id,
            ChannelEnum::WEBSOCKET,
            $message,
            $response
        );

        return CommandResponseDTO::agentResponse(
            $agentId,
            $agent->name ?? $agentId,
            $response,
            $agent->provider ?? null,
            $agent->model ?? null,
            $conversation->conversation_id
        );
    }

    /**
     * Format uptime in human-readable format.
     *
     * @param  int  $seconds  Uptime in seconds
     * @return string Formatted uptime
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

    /**
     * Get a welcome/connected response.
     *
     * @param  string  $serverVersion  Server version string
     */
    public function getWelcome(string $serverVersion = '1.0.0'): CommandResponseDTO
    {
        return CommandResponseDTO::connected($serverVersion);
    }

    /**
     * Check if a message is a valid command.
     *
     * @param  string  $message  The message to check
     * @return bool True if the message is a command
     */
    public function isCommand(string $message): bool
    {
        $message = trim($message);

        return str_starts_with($message, '/') || preg_match('/^@[a-zA-Z0-9_-]+/', $message);
    }

    /**
     * Get list of available commands.
     *
     * @return array<int, string>
     */
    public function getAvailableCommands(): array
    {
        return [
            '/agents',
            '/teams',
            '/status',
            '/history [n]',
            '/reset [@agent_id ...]',
            '/ping',
            '/pong',
            '/help',
        ];
    }

    // ==========================================
    // Channel-Specific Methods (for Telegram, Discord, WhatsApp)
    // ==========================================

    /**
     * Handle channel commands (for Telegram, Discord, WhatsApp).
     *
     * These commands use ! or / prefix and have slightly different behavior
     * than WebSocket commands (e.g., /agent instead of /agents).
     *
     * @param  string  $message  The command message
     * @return CommandResponseDTO|null Returns null if not a command
     */
    public function handleChannelCommand(string $message): ?CommandResponseDTO
    {
        return $this->channelHandler->handle($message);
    }

    /**
     * Check if a message is a channel command. Delegates to ChannelCommandHandler.
     */
    public function isChannelCommand(string $message): bool
    {
        return $this->channelHandler->isChannelCommand($message);
    }

    // ==========================================
    // CLI-Specific Methods (with intelligent routing)
    // ==========================================

    /**
     * Process a message with intelligent routing (CLI-style).
     *
     * This method uses the RoutingService for intelligent agent selection,
     * intent classification, and skill matching. It's designed for CLI use
     * where more context is needed about the routing decision.
     *
     * @param  string  $message  The message to process
     * @param  array<string, mixed>  $options  Options: agent, team, reset
     * @return array{response: CommandResponseDTO, routing: RoutingResultDTO|null}
     */
    public function processWithRouting(string $message, array $options = []): array
    {
        $message = trim($message);

        if (empty($message)) {
            return [
                'response' => CommandResponseDTO::error('Empty message', 400),
                'routing' => null,
            ];
        }

        // Get agents and teams
        $agents = $this->settings->getAgents();
        $teams = $this->settings->getTeams();

        // Check if agents are configured
        if ($agents->isEmpty()) {
            return [
                'response' => CommandResponseDTO::error('No agents configured. Run `php artisan laraclaw:setup` to configure LaraClaw.'),
                'routing' => null,
            ];
        }

        // Determine routing
        $agentId = $options['agent'] ?? null;
        $teamId = $options['team'] ?? null;
        $isTeamRouted = false;
        $routingResult = null;

        if ($teamId) {
            // Explicit team routing
            if (! isset($teams[$teamId])) {
                return [
                    'response' => CommandResponseDTO::error("Team '{$teamId}' not found."),
                    'routing' => null,
                ];
            }
            $agentId = $teams[$teamId]->leader_agent_id;
            $isTeamRouted = true;
        } elseif (! $agentId && $this->routingService) {
            // Use intelligent routing with intent classification
            $routingResult = $this->routingService->route($message, $agents, $teams);
            $agentId = $routingResult->agentId;
            $isTeamRouted = $routingResult->isTeamRouted;
            $teamId = $routingResult->teamId;
        } elseif (! $agentId) {
            // Fallback to default agent
            $agentId = $this->settings->getDefaultAgentId();
        }

        // Verify agent exists
        if (! isset($agents[$agentId])) {
            return [
                'response' => CommandResponseDTO::error(
                    "Agent '{$agentId}' not found. Available agents: ".$agents->keys()->implode(', ')
                ),
                'routing' => null,
            ];
        }

        $agent = $agents[$agentId];
        $shouldReset = $options['reset'] ?? false;

        MultiLogger::info('Processing message with routing', [
            'agent_id' => $agentId,
            'team_id' => $teamId,
            'is_team_routed' => $isTeamRouted,
        ]);

        try {
            // Invoke the agent
            $response = $this->invokerService->invokeAgent(
                $agent,
                $agentId,
                $message,
                $shouldReset,
                $agents,
                $teams
            );

            // Save chat history
            $conversationId = $this->chatHistoryService->saveConversation(
                'cli',
                'user',
                $message,
                [[
                    'agentId' => $agentId,
                    'agentName' => $agent->name,
                    'provider' => $agent->provider ?? null,
                    'model' => $agent->model ?? null,
                    'response' => $response,
                ]],
                $teamId
            );

            // Record episodic memory (fire-and-forget)
            if ($this->memoryService && $this->memoryService->isEnabled()) {
                $content = $this->memoryService->truncateText('User: '.$message);
                $outcome = $this->memoryService->truncateText($response);

                if ($content !== null && $outcome !== null) {
                    try {
                        $this->memoryService->recordEvent(
                            'cli-user',
                            ChannelEnum::CLI,
                            [
                                'type' => 'task_completed',
                                'content' => $content,
                                'outcome' => $outcome,
                                'agent_id' => $agentId,
                            ]
                        );
                    } catch (\Exception $e) {
                        MultiLogger::warning("Failed to record episodic event: {$e->getMessage()}");
                    }
                }
            }

            // Build response with routing metadata
            $responseData = [
                'agent_id' => $agentId,
                'agent_name' => $agent->name,
                'model' => $agent->model,
                'provider' => $agent->provider,
                'team_id' => $teamId,
                'is_team_routed' => $isTeamRouted,
                'conversation_id' => $conversationId,
            ];

            // Add routing info if available
            if ($routingResult) {
                $responseData['routing_method'] = $routingResult->routingMethod;
                if ($routingResult->classification) {
                    $responseData['intent'] = $routingResult->classification->intent;
                    $responseData['intent_confidence'] = $routingResult->classification->confidence;
                }
                if (! empty($routingResult->suggestedSkills)) {
                    $responseData['suggested_skills'] = $routingResult->suggestedSkills;
                }
            }

            return [
                'response' => new CommandResponseDTO(
                    type: 'response',
                    message: $response,
                    data: $responseData,
                    code: 200,
                    success: true
                ),
                'routing' => $routingResult ?? new RoutingResultDTO(
                    agentId: $agentId,
                    message: $message,
                    isTeamRouted: $isTeamRouted,
                    teamId: $teamId,
                    routingMethod: 'default',
                ),
            ];

        } catch (\Exception $e) {
            MultiLogger::error("CLI processing error: {$e->getMessage()}", [
                'agent_id' => $agentId,
                'message' => $message,
            ]);

            return [
                'response' => CommandResponseDTO::error($e->getMessage(), 500),
                'routing' => new RoutingResultDTO(
                    agentId: $agentId,
                    message: $message,
                    routingMethod: 'error',
                ),
            ];
        }
    }

    /**
     * Get the routing service.
     */
    public function getRoutingService(): ?RoutingService
    {
        return $this->routingService;
    }

    /**
     * Get the settings service.
     */
    public function getSettings(): SettingsService
    {
        return $this->settings;
    }

    /**
     * Get the chat history service.
     */
    public function getChatHistory(): ConversationHistoryService
    {
        return $this->chatHistoryService;
    }

    /**
     * Get the agent invoker service.
     */
    public function getInvoker(): AgentInvokerService
    {
        return $this->invokerService;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Logging\MultiLogger;
use App\Models\Event;
use App\Services\AgentInvokerService;
use App\Services\ConversationHistoryService;
use App\Services\MemoryEngineService;
use App\Services\SettingsService;
use Illuminate\Console\OutputStyle;

use function Safe\preg_match;

/**
 * Processes user messages through the agent system in the interactive chat.
 *
 * Handles @agent_id / @team_id routing, agent invocation, history
 * saving, event emission, and error handling.
 */
class ChatMessageProcessor
{
    protected string $senderId = 'cli-user';

    protected ?MemoryEngineService $memoryService = null;

    public function __construct(
        protected SettingsService $settings,
        protected AgentInvokerService $invokerService,
        protected ConversationHistoryService $chatHistoryService,
        protected ChatShellRenderer $renderer,
    ) {}

    /**
     * Set the sender ID for context.
     */
    public function setSenderId(string $senderId): self
    {
        $this->senderId = $senderId;

        return $this;
    }

    /**
     * Set the memory service for lossless context tracking.
     */
    public function setMemoryService(MemoryEngineService $memoryService): self
    {
        $this->memoryService = $memoryService;
        $this->chatHistoryService->setMemoryService($memoryService);

        return $this;
    }

    /**
     * Process a message through the agent system.
     */
    public function processMessage(
        string $message,
        OutputStyle $output,
        string $defaultAgentId,
        ?string $defaultTeamId,
        bool &$shouldReset,
    ): void {
        $agents = $this->settings->getAgents();
        $teams = $this->settings->getTeams();

        // Determine routing
        $agentId = $defaultAgentId;
        $teamId = $defaultTeamId;
        $isTeamRouted = $teamId !== null;

        // Check for @agent_id or @team_id prefix in message
        if (preg_match('/^@(\S+)\s+([\s\S]*)$/', $message, $matches)) {
            $candidateId = strtolower($matches[1]);
            $remainingMessage = $matches[2];

            // Check if it's an agent
            if (isset($agents[$candidateId])) {
                $agentId = $candidateId;
                $message = $remainingMessage;
                $teamId = $this->findTeamForAgent($agentId);
                $isTeamRouted = $teamId !== null;
            }
            // Check if it's a team
            elseif (isset($teams[$candidateId])) {
                $teamId = $candidateId;
                $agentId = $teams[$candidateId]->leader_agent_id;
                $message = $remainingMessage;
                $isTeamRouted = true;
            }
        }

        // Verify agent exists
        if (! isset($agents[$agentId])) {
            $output->writeln("<error>Agent '@{$agentId}' not found.</error>");

            return;
        }

        $agent = $agents[$agentId];

        // Display routing info
        $this->renderer->displayRoutingInfo($output, $agentId, $agent, $isTeamRouted, $teamId);

        // Emit events
        Event::emit('message_received', ['channel' => 'cli', 'sender' => 'user', 'message' => $message]);
        Event::emit('agent_routed', ['agentId' => $agentId, 'teamId' => $teamId, 'isTeamRouted' => $isTeamRouted]);

        try {
            $startTime = microtime(true);

            $response = $this->invokerService->invokeAgent(
                $agent,
                $agentId,
                $message,
                $shouldReset,
                $agents,
                $teams
            );

            $duration = round(microtime(true) - $startTime, 2);

            // Reset the reset flag after use
            $shouldReset = false;

            // Save chat history
            $this->chatHistoryService->saveConversation(
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

            // Emit completion event
            Event::emit('response_ready', [
                'channel' => 'cli',
                'agentId' => $agentId,
                'sender' => 'user',
                'responseLength' => strlen($response),
            ]);

            // Display response
            $this->renderer->displayResponse($output, $response, $duration);

        } catch (\Exception $e) {
            MultiLogger::error("Shell command error: {$e->getMessage()}", [
                'agent_id' => $agentId,
                'message' => $message,
                'exception' => $e,
            ]);

            $this->renderer->displayError($output, $e->getMessage());
        }
    }

    /**
     * Find a team that contains the given agent.
     */
    protected function findTeamForAgent(string $agentId): ?string
    {
        $teams = $this->settings->getTeams();
        foreach ($teams as $teamId => $team) {
            if ($team->hasAgent($agentId)) {
                return $teamId;
            }
        }

        return null;
    }
}

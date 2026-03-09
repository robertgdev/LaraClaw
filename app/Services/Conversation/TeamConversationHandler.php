<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\DTOs\ConversationStateDTO;
use App\Logging\MultiLogger;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Event;
use App\Services\ConversationHistoryService;
use App\Services\ConversationStateManagerService;
use App\Services\RoutingService;
use App\TypedCollections\AgentCollection;
use App\TypedCollections\TeamCollection;

use function Safe\preg_replace;

/**
 * Handles team-based multi-agent conversations with message passing.
 *
 * Manages conversation state, teammate mentions, internal message routing,
 * and final response aggregation for team conversations.
 */
class TeamConversationHandler
{
    public function __construct(
        protected ResponseDeliveryService $deliveryService
    ) {}

    /**
     * Handle a team-based response with conversation tracking.
     */
    public function handle(
        ConversationMessage $message,
        string $response,
        string $agentId,
        Agent $agent,
        \App\Models\Team $team,
        AgentCollection $agents,
        TeamCollection $teams,
        RoutingService $routingService,
        ConversationHistoryService $chatHistory,
        ConversationStateManagerService $conversationManager
    ): void {
        $isInternal = ! empty($message->is_internal);

        // Get or create conversation
        $conv = null;
        if ($isInternal && $message->conversation_id) {
            $conv = $conversationManager->get($message->conversation_id);
        }

        if (! $conv) {
            $conv = new ConversationStateDTO(
                channel: $message->channel->value,
                sender: $message->sender,
                senderId: $message->sender_id,
                originalMessage: $message->original_message ?? $message->message,
                messageId: $message->message_id,
                teamContext: $team->toArray(),
            );
            $conversationManager->create($conv);

            MultiLogger::info("Conversation started: {$conv->id} (team: {$team->name})");
            Event::emit('team_chain_start', [
                'teamId' => $team->team_id,
                'teamName' => $team->name,
                'agents' => $team->getAgentIds(),
                'leader' => $team->leader,
            ]);
        }

        // Record this agent's response with provider/model info
        $conv->addResponse($agentId, $response, $agent['name'], $agent['provider'] ?? null, $agent['model'] ?? null);
        $conv->addFiles($this->deliveryService->collectFiles($response));
        $conversationManager->update($conv);

        // Check for teammate mentions
        $teammateMentions = $routingService->extractTeammateMentions(
            $response,
            $agentId,
            $team->team_id,
            $teams,
            $agents
        );

        if (! empty($teammateMentions) && ! $conv->isMaxMessagesReached()) {
            // Enqueue internal messages for each mention
            $conv->incrementPending(count($teammateMentions));
            $conversationManager->update($conv);

            foreach ($teammateMentions as $mention) {
                MultiLogger::info("@{$agentId} → @{$mention['teammateId']}");
                Event::emit('chain_handoff', [
                    'teamId' => $team->team_id,
                    'fromAgent' => $agentId,
                    'toAgent' => $mention['teammateId'],
                ]);

                $internalMsg = "[Message from teammate @{$agentId}]:\n{$mention['message']}";
                $this->enqueueInternalMessage(
                    $message,
                    $conv->id,
                    $agentId,
                    $mention['teammateId'],
                    $internalMsg
                );
            }
        } elseif (! empty($teammateMentions)) {
            MultiLogger::warning("Conversation {$conv->id} hit max messages ({$conv->maxMessages}) — not enqueuing further mentions");
        }

        // This branch is done
        $conv->decrementPending();
        $conversationManager->update($conv);

        if ($conv->isComplete()) {
            $this->completeConversation($message, $conv, $agents, $chatHistory, $conversationManager);
        } else {
            MultiLogger::info("Conversation {$conv->id}: {$conv->pending} branch(es) still pending");
        }

        $message->markAsCompleted();
    }

    /**
     * Complete a conversation and send the aggregated response.
     */
    protected function completeConversation(
        ConversationMessage $message,
        ConversationStateDTO $conv,
        AgentCollection $agents,
        ConversationHistoryService $chatHistory,
        ConversationStateManagerService $conversationManager
    ): void {
        MultiLogger::info("Conversation {$conv->id} complete — ".count($conv->responses)." response(s), {$conv->totalMessages} total message(s)");

        Event::emit('team_chain_end', [
            'teamId' => $conv->teamContext['teamId'],
            'totalSteps' => count($conv->responses),
            'agents' => $agents->pluck('agent_id')->toArray(),
        ]);

        // Aggregate responses
        if (count($conv->responses) === 1) {
            $finalResponse = $conv->responses[0]['response'];
            $primaryAgentId = $conv->responses[0]['agentId'];
            $primaryAgentName = $conv->responses[0]['agentName'];
            $primaryProvider = $conv->responses[0]['provider'] ?? null;
            $primaryModel = $conv->responses[0]['model'] ?? null;
        } else {
            $parts = [];
            foreach ($conv->responses as $response) {
                $parts[] = "@{$response['agentId']}: {$response['response']}";
            }
            $finalResponse = implode("\n\n------\n\n", $parts);
            $primaryAgentId = null;
            $primaryAgentName = 'Team';
            $primaryProvider = null;
            $primaryModel = null;
        }

        // Detect file references
        $outboundFiles = $conv->files;
        $outboundFiles = array_unique(array_merge($outboundFiles, $this->deliveryService->collectFiles($finalResponse)));

        // Remove [send_file: ...] tags
        if (! empty($outboundFiles)) {
            $finalResponse = preg_replace('/\[send_file:\s*[^\]]+\]/', '', $finalResponse);
            $finalResponse = trim($finalResponse);
        }

        // Remove [@agent: ...] tags from final response
        $finalResponse = preg_replace('/\[@\S+?:\s*[\s\S]*?\]/', '', $finalResponse);
        $finalResponse = trim($finalResponse);

        // Handle long responses
        $result = $this->deliveryService->handleLongResponse($finalResponse, $outboundFiles);

        // Mark the database conversation as completed
        if ($message->conversation_id) {
            $dbConversation = Conversation::where('conversation_id', $message->conversation_id)->first();
            if ($dbConversation) {
                $dbConversation->markCompleted();
            }
        }

        // Send response
        $this->deliveryService->sendResponse(
            $message,
            $result['message'],
            $primaryAgentId,
            $result['files'],
            $primaryAgentName,
            $primaryProvider,
            $primaryModel
        );

        // Clean up from cache
        $conversationManager->delete($conv->id);
    }

    /**
     * Enqueue an internal (agent-to-agent) message.
     */
    protected function enqueueInternalMessage(
        ConversationMessage $originalMessage,
        string $conversationId,
        string $fromAgent,
        string $targetAgent,
        string $messageText
    ): void {
        $internalMessage = ConversationMessage::createIncoming([
            'channel' => $originalMessage->channel,
            'sender' => $originalMessage->sender,
            'sender_id' => $originalMessage->sender_id,
            'message' => $messageText,
            'agent_id' => $targetAgent,
            'conversation_id' => $conversationId,
            'is_internal' => true,
        ]);

        // Dispatch the job
        \App\Jobs\ProcessMessageJob::dispatch($internalMessage);

        MultiLogger::info("Enqueued internal message: @{$fromAgent} → @{$targetAgent}");
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Stages;

use App\Services\Conversation\ResponseDeliveryService;
use App\Services\Conversation\TeamConversationHandler;
use App\Services\ConversationHistoryService;
use App\Services\ConversationStateManagerService;
use App\Services\Pipeline\MessagePipelineStage;
use App\Services\Pipeline\MessageProcessingContext;
use App\Services\RoutingService;

/**
 * ResponseDeliveryStage - Delivers the response back to the user.
 *
 * For simple (non-team) responses, prepares and sends directly.
 * For team conversations, delegates to TeamConversationHandler.
 */
class ResponseDeliveryStage implements MessagePipelineStage
{
    protected ResponseDeliveryService $deliveryService;

    protected TeamConversationHandler $teamHandler;

    protected RoutingService $routingService;

    protected ConversationHistoryService $chatHistory;

    protected ConversationStateManagerService $conversationManager;

    public function __construct(
        ResponseDeliveryService $deliveryService,
        TeamConversationHandler $teamHandler,
        RoutingService $routingService,
        ConversationHistoryService $chatHistory,
        ConversationStateManagerService $conversationManager
    ) {
        $this->deliveryService = $deliveryService;
        $this->teamHandler = $teamHandler;
        $this->routingService = $routingService;
        $this->chatHistory = $chatHistory;
        $this->conversationManager = $conversationManager;
    }

    public function process(MessageProcessingContext $context): MessageProcessingContext
    {
        // DEBUG: Log the state before processing
        /*
        \App\Logging\MultiLogger::info('[DEBUG] ResponseDeliveryStage::process() called', [
            'has_teamContext' => $context->teamContext !== null,
            'has_session' => $context->session !== null,
            'session_id' => $context->session?->id,
            'session_conversation_id' => $context->session?->conversation_id,
            'message_id' => $context->message->id,
            'message_conversation_id' => $context->message->conversation_id,
        ]);
        */

        if (! $context->teamContext) {
            // Simple response
            $result = $this->deliveryService->prepareSimpleResponse($context->response);

            if ($context->session) {
                $context->session->markCompleted();
            } else {
                // DEBUG: Log when session is null
                \App\Logging\MultiLogger::warning('[DEBUG] ResponseDeliveryStage: session is NULL, cannot mark completed');
            }

            $this->deliveryService->sendResponse(
                $context->message,
                $result->message,
                $context->agentId,
                $result->files,
                $context->agent['name'],
                $context->agent['provider'] ?? null,
                $context->agent['model'] ?? null
            );
        } else {
            // Team conversation
            $this->teamHandler->handle(
                $context->message,
                $context->response,
                $context->agentId,
                $context->agent,
                $context->teamContext,
                $context->agents,
                $context->teams,
                $this->routingService,
                $this->chatHistory,
                $this->conversationManager
            );
        }

        return $context;
    }
}

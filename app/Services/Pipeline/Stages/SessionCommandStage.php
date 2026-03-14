<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Stages;

use App\Services\Conversation\ResponseDeliveryService;
use App\Services\Pipeline\MessagePipelineStage;
use App\Services\Pipeline\MessageProcessingContext;
use App\Services\SessionService;
use App\Services\Skills\SkillDiscoveryHandler;

/**
 * SessionCommandStage - Handles session commands and pending skill discovery.
 *
 * For external (non-internal) messages, this stage:
 * 1. Checks if the message is a session command (new session, list sessions, etc.)
 * 2. Checks if the message is a response to a pending skill discovery prompt
 *
 * If either condition is met, the response is sent immediately and the
 * context is marked as handled.
 */
class SessionCommandStage implements MessagePipelineStage
{
    protected SessionService $sessionService;

    protected ResponseDeliveryService $deliveryService;

    protected SkillDiscoveryHandler $discoveryHandler;

    public function __construct(
        SessionService $sessionService,
        ResponseDeliveryService $deliveryService,
        SkillDiscoveryHandler $discoveryHandler
    ) {
        $this->sessionService = $sessionService;
        $this->deliveryService = $deliveryService;
        $this->discoveryHandler = $discoveryHandler;
    }

    public function process(MessageProcessingContext $context): MessageProcessingContext
    {
        if ($context->isInternal) {
            return $context;
        }

        // Session commands
        $sessionIntent = $this->sessionService->detectSessionIntent($context->message->message);
        if ($sessionIntent) {
            $result = $this->sessionService->handleSessionIntent(
                $sessionIntent,
                $context->message->message,
                $context->message->channel,
                $context->message->sender_id ?? $context->message->sender,
                $context->message->sender
            );

            if ($result->handled) {
                $this->deliveryService->sendResponse($context->message, $result->response);
                $context->markHandled($result->response);

                return $context;
            }
        }

        // Pending skill discovery selection
        $senderId = $context->message->sender_id ?? $context->message->sender;
        if ($this->discoveryHandler->handlePendingSelection($context->message, $senderId)) {
            $context->markHandled();

            return $context;
        }

        return $context;
    }
}

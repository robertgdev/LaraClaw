<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Stages;

use App\Logging\MultiLogger;
use App\Models\Event;
use App\Services\Conversation\ResponseDeliveryService;
use App\Services\Pipeline\MessagePipelineStage;
use App\Services\Pipeline\MessageProcessingContext;
use App\Services\RoutingService;
use App\Services\SettingsService;
use Illuminate\Support\Str;

/**
 * RoutingStage - Resolves agent routing from the message.
 *
 * Determines which agent should handle the message by:
 * 1. Checking for explicit @agent mentions
 * 2. Applying fallbacks (default agent, first available agent)
 * 3. Emitting routing events
 * 4. Handling easter egg for multiple agent mentions
 */
class RoutingStage implements MessagePipelineStage
{
    protected RoutingService $routingService;

    protected SettingsService $settings;

    protected ResponseDeliveryService $deliveryService;

    public function __construct(
        RoutingService $routingService,
        SettingsService $settings,
        ResponseDeliveryService $deliveryService
    ) {
        $this->routingService = $routingService;
        $this->settings = $settings;
        $this->deliveryService = $deliveryService;
    }

    public function process(MessageProcessingContext $context): MessageProcessingContext
    {
        // Emit initial event for external messages
        if (! $context->isInternal) {
            Event::emit('message_received', [
                'channel' => $context->message->channel,
                'sender' => $context->message->sender,
                'message' => Str::limit($context->message->message, 120),
                'messageId' => $context->message->message_id,
            ]);
        }

        // Load agents and teams
        $context->agents = $this->settings->getAgents();
        $context->teams = $this->settings->getTeams();

        // Resolve routing
        $agentId = $context->message->agent_id;
        $message = $context->message->message;
        $isTeamRouted = false;
        $routing = null;

        if (! $agentId || ! isset($context->agents[$agentId])) {
            $routing = $this->routingService->parseAgentRouting($message, $context->agents, $context->teams);
            $agentId = $routing->agentId;
            $message = $routing->message;
            $isTeamRouted = $routing->isTeam;
        }

        // Easter egg: multiple agent mentions
        if (! $context->isInternal && $agentId === 'error' && $routing !== null) {
            MultiLogger::info('Multiple agents detected, sending easter egg message');
            $this->deliveryService->sendResponse($context->message, $routing->message);
            $context->markHandled($routing->message);

            return $context;
        }

        // Agent fallbacks
        if (! $context->agents->has($agentId)) {
            $agentId = $this->settings->getDefaultAgentId();
        }
        if (! $context->agents->has($agentId)) {
            $agentId = $context->agents->keys()->first();
        }

        $agent = $context->agents->get($agentId);
        if (! $agent) {
            throw new \RuntimeException("Agent not found: {$agentId}");
        }

        // Set routing context
        $context->agentId = $agentId;
        $context->agent = $agent;
        $context->processedMessage = $message;
        $context->isTeamRouted = $isTeamRouted;
        $context->routing = $routing;

        MultiLogger::info("Routing to agent: {$agent['name']} ({$agentId}) [{$agent['provider']}/{$agent['model']}]");

        if (! $context->isInternal) {
            Event::emit('agent_routed', [
                'agentId' => $agentId,
                'agentName' => $agent['name'],
                'provider' => $agent['provider'],
                'model' => $agent['model'],
                'isTeamRouted' => $isTeamRouted,
            ]);
        }

        return $context;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Stages;

use App\Jobs\LosslessCompactionJob;
use App\Models\Conversation;
use App\Models\Event;
use App\Services\AgentInvokerService;
use App\Services\ConversationStateManagerService;
use App\Services\MemoryEngineService;
use App\Services\Pipeline\MessagePipelineStage;
use App\Services\Pipeline\MessageProcessingContext;
use App\Services\ResponseParserService;
use App\Services\RoutingService;
use App\Services\ScriptExecutionService;
use App\Services\SessionService;
use App\Services\SkillSearchService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * AgentInvocationStage - Invokes the agent and generates a response.
 *
 * This stage:
 * 1. Checks the per-agent reset flag
 * 2. Appends pending indicators for internal team messages
 * 3. Gets or creates the active session
 * 4. Invokes the agent (or uses direct execution result)
 * 5. Appends message to lossless context if enabled
 */
class AgentInvocationStage implements MessagePipelineStage
{
    protected AgentInvokerService $invokerService;

    protected SkillSearchService $skillService;

    protected MemoryEngineService $memoryService;

    protected ScriptExecutionService $scriptExecutionService;

    protected RoutingService $routingService;

    protected ConversationStateManagerService $conversationManager;

    protected SessionService $sessionService;

    public function __construct(
        AgentInvokerService $invokerService,
        SkillSearchService $skillService,
        MemoryEngineService $memoryService,
        ScriptExecutionService $scriptExecutionService,
        RoutingService $routingService,
        ConversationStateManagerService $conversationManager,
        SessionService $sessionService
    ) {
        $this->invokerService = $invokerService;
        $this->skillService = $skillService;
        $this->memoryService = $memoryService;
        $this->scriptExecutionService = $scriptExecutionService;
        $this->routingService = $routingService;
        $this->conversationManager = $conversationManager;
        $this->sessionService = $sessionService;
    }

    public function process(MessageProcessingContext $context): MessageProcessingContext
    {
        // Per-agent reset flag
        $resetFlag = $this->routingService->getAgentResetFlag($context->agentId);
        $context->shouldReset = File::exists($resetFlag);
        if ($context->shouldReset) {
            File::delete($resetFlag);
        }

        // Append pending indicator for internal messages
        if ($context->isInternal && $context->messageData['conversationId']) {
            $conv = $this->conversationManager->get($context->messageData['conversationId']);
            if ($conv) {
                $othersPending = $conv->pending - 1;
                if ($othersPending > 0) {
                    $context->processedMessage .= "\n\n------\n\n[{$othersPending} other teammate response(s) are still being processed and will be delivered when ready. Do not re-mention teammates who haven't responded yet.]";
                }
            }
        }

        // Session management
        $context->session = $this->getOrCreateSession($context);

        // DEBUG: Log session creation
        /*
        \App\Logging\MultiLogger::info('[DEBUG] AgentInvocationStage: session set', [
            'session_id' => $context->session?->id,
            'session_conversation_id' => $context->session?->conversation_id,
            'message_conversation_id' => $context->message->conversation_id,
        ]);
        */

        // Update session metadata
        $firstMessage = $context->session->getFirstUserMessage();
        $updateData = ['last_message_at' => now()];
        if ($firstMessage && empty($context->session->label) && empty($context->session->derived_title)) {
            $updateData['derived_title'] = Str::limit($firstMessage->message, 100);
        }
        $context->session->update($updateData);

        // Append message to lossless context if enabled
        $this->appendToLosslessContext($context);

        // Generate response
        if ($context->directExecutionResult !== null) {
            $context->response = $context->directExecutionResult;
        } else {
            $context->response = $this->invokeAgent($context);
        }

        return $context;
    }

    /**
     * Get or create the active session.
     */
    protected function getOrCreateSession(MessageProcessingContext $context): Conversation
    {
        $session = null;
        if ($context->message->conversation_id) {
            $session = Conversation::where('conversation_id', $context->message->conversation_id)->first();
        }

        if (! $session) {
            $session = $this->sessionService->getOrCreateActiveSession(
                $context->message->channel,
                $context->message->sender_id ?? $context->message->sender,
                $context->message->sender
            );
        }

        return $session;
    }

    /**
     * Invoke the agent with all context wired up.
     */
    protected function invokeAgent(MessageProcessingContext $context): string
    {
        $this->invokerService->setSkillService($this->skillService);
        $this->invokerService->setMemoryService($this->memoryService);
        $this->invokerService->setChannel($context->message->channel);
        $this->invokerService->setSenderId($context->message->sender_id ?? $context->message->sender);

        // Pass conversation ID for lossless memory context
        if ($context->session) {
            $this->invokerService->setConversationId($context->session->id);
        }

        $responseParser = new ResponseParserService($this->scriptExecutionService);
        $this->invokerService->setResponseParser($responseParser);

        Event::emit('chain_step_start', [
            'agentId' => $context->agentId,
            'agentName' => $context->agent['name'],
            'fromAgent' => $context->messageData['fromAgent'] ?? null,
        ]);

        $response = $this->invokerService->invokeAgent(
            $context->agent,
            $context->agentId,
            $context->processedMessage,
            $context->shouldReset,
            $context->agents,
            $context->teams
        );

        Event::emit('chain_step_done', [
            'agentId' => $context->agentId,
            'agentName' => $context->agent['name'],
            'responseLength' => strlen($response),
            'responseText' => $response,
        ]);

        return $response;
    }

    /**
     * Append the message to the lossless context if enabled.
     * Also checks if compaction is needed and dispatches a background job.
     */
    protected function appendToLosslessContext(MessageProcessingContext $context): void
    {
        if (! $this->memoryService->isLosslessEnabled()) {
            return;
        }

        if (! $context->session) {
            return;
        }

        try {
            $this->memoryService->appendMessageToContext(
                $context->session->id,
                $context->message->id
            );

            // Check if compaction is needed and dispatch background job
            $this->dispatchCompactionIfNeeded($context->session->id);
        } catch (\Exception $e) {
            \App\Logging\MultiLogger::warning("Failed to append to lossless context: {$e->getMessage()}");
        }
    }

    /**
     * Check if compaction is needed and dispatch a background job.
     */
    protected function dispatchCompactionIfNeeded(int $conversationId): void
    {
        try {
            $tokenBudget = (int) config('laraclaw.memory.lossless_token_budget', 100000);
            $decision = $this->memoryService->evaluateCompaction($conversationId, $tokenBudget);

            if ($decision->shouldCompact) {
                // Dispatch to 'compaction' queue for isolation from message processing
                LosslessCompactionJob::dispatch($conversationId)
                    ->onQueue('compaction');

                \App\Logging\MultiLogger::info('Lossless compaction job dispatched', [
                    'conversation_id' => $conversationId,
                    'current_tokens' => $decision->currentTokens,
                    'threshold' => $decision->threshold,
                ]);
            }
        } catch (\Exception $e) {
            \App\Logging\MultiLogger::warning("Failed to evaluate/dispatch compaction: {$e->getMessage()}");
        }
    }
}

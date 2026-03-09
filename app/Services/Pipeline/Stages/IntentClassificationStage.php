<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Stages;

use App\Logging\MultiLogger;
use App\Models\Team;
use App\Services\ConversationStateManagerService;
use App\Services\IntentClassificationService;
use App\Services\Pipeline\MessagePipelineStage;
use App\Services\Pipeline\MessageProcessingContext;
use App\Services\Skills\SkillDiscoveryHandler;
use App\Services\Skills\SkillExecutionHandler;
use App\Services\SkillSearchService;

/**
 * IntentClassificationStage - Classifies intent and attempts direct skill execution.
 *
 * For external messages, this stage:
 * 1. Runs intent classification via the IntentClassificationService
 * 2. Attempts direct skill execution for high-confidence matches
 * 3. Resolves team context (from internal message data or routing)
 * 4. Detects skill gaps and triggers auto-discovery
 */
class IntentClassificationStage implements MessagePipelineStage
{
    protected IntentClassificationService $intentService;

    protected SkillSearchService $skillService;

    protected SkillExecutionHandler $skillExecutionHandler;

    protected SkillDiscoveryHandler $discoveryHandler;

    protected ConversationStateManagerService $conversationManager;

    public function __construct(
        IntentClassificationService $intentService,
        SkillSearchService $skillService,
        SkillExecutionHandler $skillExecutionHandler,
        SkillDiscoveryHandler $discoveryHandler,
        ConversationStateManagerService $conversationManager
    ) {
        $this->intentService = $intentService;
        $this->skillService = $skillService;
        $this->skillExecutionHandler = $skillExecutionHandler;
        $this->discoveryHandler = $discoveryHandler;
        $this->conversationManager = $conversationManager;
    }

    public function process(MessageProcessingContext $context): MessageProcessingContext
    {
        // Intent classification & direct skill execution (external messages only)
        if (! $context->isInternal) {
            $this->intentService->setSkillService($this->skillService);
            $context->classification = $this->intentService->classify($context->processedMessage);

            MultiLogger::info('Intent classification result', [
                'intent' => $context->classification->intent,
                'confidence' => $context->classification->confidence,
                'matched_skill' => $context->classification->matchedSkill,
                'skill_confidence' => $context->classification->skillConfidence,
                'method' => $context->classification->method,
                'from_cache' => $context->classification->fromCache,
            ]);

            // Direct skill execution for high-confidence matches
            $directExecutionThreshold = config('laraclaw.skills.direct_execution_threshold', 0.85);
            if ($context->classification->matchedSkill
                && $context->classification->skillConfidence >= $directExecutionThreshold) {
                MultiLogger::info("High-confidence skill match: {$context->classification->matchedSkill} (confidence: {$context->classification->skillConfidence})");

                $context->directExecutionResult = $this->skillExecutionHandler->tryDirectExecution(
                    $context->classification->matchedSkill,
                    $context->processedMessage,
                    $context->agentId,
                );

                if ($context->directExecutionResult !== null) {
                    MultiLogger::info('Direct skill execution successful', [
                        'skill' => $context->classification->matchedSkill,
                        'output_length' => strlen($context->directExecutionResult),
                    ]);
                }
            }
        }

        // Resolve team context
        $context->teamContext = $this->resolveTeamContext($context);

        return $context;
    }

    /**
     * Resolve team context for the message.
     */
    protected function resolveTeamContext(MessageProcessingContext $context): ?Team
    {
        if ($context->isInternal && $context->messageData['conversationId']) {
            $conv = $this->conversationManager->get($context->messageData['conversationId']);
            if ($conv) {
                return $conv->teamContext
                    ? Team::where('team_id', $conv->teamContext['teamId'] ?? '')->first()
                    : null;
            }
        }

        // Skill gap detection for external messages
        if (! $context->isInternal && $context->classification !== null) {
            $senderId = $context->message->sender_id ?? $context->message->sender;
            if ($this->discoveryHandler->detectAndHandle($context->message, $context->classification, $senderId, $context->agentId)) {
                return null;
            }
        }

        $teamContext = null;
        if ($context->isTeamRouted) {
            $teamContext = Team::findTeamForAgentLeader($context->agentId);
        }

        return $teamContext ?? Team::findTeamForAgent($context->agentId);
    }
}

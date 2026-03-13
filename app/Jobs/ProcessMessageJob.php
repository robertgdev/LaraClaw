<?php

namespace App\Jobs;

use App\Logging\MultiLogger;
use App\Models\ConversationMessage;
use App\Services\AgentInvokerService;
use App\Services\Conversation\ResponseDeliveryService;
use App\Services\Conversation\TeamConversationHandler;
use App\Services\ConversationHistoryService;
use App\Services\ConversationStateManagerService;
use App\Services\IntentClassificationService;
use App\Services\MemoryEngineService;
use App\Services\Pipeline\MessageProcessingPipeline;
use App\Services\Pipeline\Stages\AgentInvocationStage;
use App\Services\Pipeline\Stages\IntentClassificationStage;
use App\Services\Pipeline\Stages\ResponseDeliveryStage;
use App\Services\Pipeline\Stages\RoutingStage;
use App\Services\Pipeline\Stages\SessionCommandStage;
use App\Services\QueueRoutingService;
use App\Services\RoutingService;
use App\Services\ScriptExecutionService;
use App\Services\SessionService;
use App\Services\SettingsService;
use App\Services\SkillAutoDiscoveryService;
use App\Services\Skills\SkillDiscoveryHandler;
use App\Services\Skills\SkillExecutionHandler;
use App\Services\SkillSearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ProcessMessageJob - Processes incoming messages through the agent system.
 *
 * This job orchestrates the message processing pipeline by assembling
 * discrete stages and running them in sequence:
 *
 * 1. SessionCommandStage - Handles session commands and pending skill discovery
 * 2. RoutingStage - Resolves which agent should handle the message
 * 3. IntentClassificationStage - Classifies intent and attempts direct skill execution
 * 4. AgentInvocationStage - Invokes the agent and generates a response
 * 5. ResponseDeliveryStage - Delivers the response to the user
 *
 * Heavy logic is delegated to pipeline stage classes and handler services:
 * - SkillExecutionHandler: Direct skill execution without LLM
 * - SkillDiscoveryHandler: Skill gap detection and auto-installation
 * - TeamConversationHandler: Multi-agent conversation orchestration
 * - ResponseDeliveryService: Response packaging and delivery
 */
class ProcessMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected ConversationMessage $message;

    protected int $maxConversationMessages;

    protected int $longResponseThreshold;

    /**
     * Create a new job instance.
     *
     * The queue is dynamically assigned based on the agent and configured strategy.
     */
    public function __construct(ConversationMessage $message)
    {
        $this->message = $message;
        $this->maxConversationMessages = config('laraclaw.conversation.max_messages', 50);
        $this->longResponseThreshold = config('laraclaw.conversation.long_response_threshold', 4000);

        $queueRouting = app(QueueRoutingService::class);
        $this->onQueue($queueRouting->getQueueForAgent($message->agent_id));
    }

    /**
     * Execute the job.
     */
    public function handle(
        SettingsService $settings,
        ConversationHistoryService $chatHistory,
        RoutingService $routingService,
        AgentInvokerService $invokerService,
        ConversationStateManagerService $conversationManager,
        SessionService $sessionService,
        ScriptExecutionService $scriptExecutionService,
        IntentClassificationService $intentService,
        SkillSearchService $skillService,
        MemoryEngineService $memoryService,
        SkillAutoDiscoveryService $autoDiscoveryService
    ): void {
        $this->message->markAsProcessing();

        // Build handler instances
        $deliveryService = new ResponseDeliveryService($this->longResponseThreshold);
        $skillExecutionHandler = new SkillExecutionHandler($scriptExecutionService, $skillService);
        $discoveryHandler = new SkillDiscoveryHandler($autoDiscoveryService, $deliveryService);
        $teamHandler = new TeamConversationHandler($deliveryService);

        try {
            // Build and run the pipeline
            $pipeline = $this->buildPipeline(
                $sessionService,
                $deliveryService,
                $discoveryHandler,
                $routingService,
                $settings,
                $intentService,
                $skillService,
                $skillExecutionHandler,
                $conversationManager,
                $invokerService,
                $memoryService,
                $scriptExecutionService,
                $teamHandler,
                $chatHistory
            );

            $pipeline->run($this->message);

        } catch (\Exception $e) {
            MultiLogger::error("Processing error: {$e->getMessage()}");
            $this->message->markAsFailed($e->getMessage());

            throw $e;
        }
    }

    /**
     * Build the message processing pipeline with all stages.
     */
    protected function buildPipeline(
        SessionService $sessionService,
        ResponseDeliveryService $deliveryService,
        SkillDiscoveryHandler $discoveryHandler,
        RoutingService $routingService,
        SettingsService $settings,
        IntentClassificationService $intentService,
        SkillSearchService $skillService,
        SkillExecutionHandler $skillExecutionHandler,
        ConversationStateManagerService $conversationManager,
        AgentInvokerService $invokerService,
        MemoryEngineService $memoryService,
        ScriptExecutionService $scriptExecutionService,
        TeamConversationHandler $teamHandler,
        ConversationHistoryService $chatHistory
    ): MessageProcessingPipeline {
        $pipeline = new MessageProcessingPipeline;

        // Stage 1: Session commands and pending skill discovery
        $pipeline->addStage(new SessionCommandStage(
            $sessionService,
            $deliveryService,
            $discoveryHandler
        ));

        // Stage 2: Agent routing
        $pipeline->addStage(new RoutingStage(
            $routingService,
            $settings,
            $deliveryService
        ));

        // Stage 3: Intent classification and direct skill execution
        $pipeline->addStage(new IntentClassificationStage(
            $intentService,
            $skillService,
            $skillExecutionHandler,
            $discoveryHandler,
            $conversationManager
        ));

        // Stage 4: Agent invocation and response generation
        $pipeline->addStage(new AgentInvocationStage(
            $invokerService,
            $skillService,
            $memoryService,
            $scriptExecutionService,
            $routingService,
            $conversationManager,
            $sessionService
        ));

        // Stage 5: Response delivery
        $pipeline->addStage(new ResponseDeliveryStage(
            $deliveryService,
            $teamHandler,
            $routingService,
            $chatHistory,
            $conversationManager
        ));

        return $pipeline;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        MultiLogger::error("ProcessMessageJob failed: {$exception->getMessage()}");
        $this->message->markAsFailed($exception->getMessage());
    }

    /**
     * Get active conversations (for debugging/monitoring).
     *
     * @return array<int>
     */
    public static function getActiveConversations(ConversationStateManagerService $manager): array
    {
        return $manager->getActiveConversationIds();
    }
}

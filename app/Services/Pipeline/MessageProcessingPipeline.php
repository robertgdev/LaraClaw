<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Logging\MultiLogger;
use App\Models\ConversationMessage;

/**
 * MessageProcessingPipeline - Orchestrates message processing through discrete stages.
 *
 * This pipeline runs a sequence of MessagePipelineStage instances.
 * Each stage can:
 * - Read and modify the shared MessageProcessingContext
 * - Mark the context as "handled" to short-circuit remaining stages
 * - Throw exceptions, which are caught by the pipeline runner
 *
 * Usage:
 *   $pipeline = new MessageProcessingPipeline();
 *   $pipeline->addStage($sessionCommandStage);
 *   $pipeline->addStage($routingStage);
 *   $pipeline->addStage($intentClassificationStage);
 *   $pipeline->addStage($agentInvocationStage);
 *   $pipeline->addStage($responseDeliveryStage);
 *   $pipeline->run($message);
 */
class MessageProcessingPipeline
{
    /** @var MessagePipelineStage[] */
    protected array $stages = [];

    /**
     * Add a stage to the pipeline.
     */
    public function addStage(MessagePipelineStage $stage): self
    {
        $this->stages[] = $stage;

        return $this;
    }

    /**
     * Run the pipeline for a given message.
     *
     * @throws \Exception If a stage throws an unrecoverable exception
     */
    public function run(ConversationMessage $message): MessageProcessingContext
    {
        $context = new MessageProcessingContext($message);

        foreach ($this->stages as $stage) {
            if (! $context->shouldContinue()) {
                break;
            }

            $stageName = (new \ReflectionClass($stage))->getShortName();
            MultiLogger::debug("Pipeline stage: {$stageName}");

            $context = $stage->process($context);
        }

        return $context;
    }

    /**
     * Get the registered stages.
     *
     * @return MessagePipelineStage[]
     */
    public function getStages(): array
    {
        return $this->stages;
    }
}

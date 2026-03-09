<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

/**
 * MessagePipelineStage - Interface for message processing pipeline stages.
 *
 * Each stage receives the context, performs its work, and returns the
 * (potentially modified) context. Stages can mark the context as "handled"
 * to short-circuit further processing.
 */
interface MessagePipelineStage
{
    /**
     * Process the context and return it (potentially modified).
     *
     * If the stage fully handles the message (e.g., a session command),
     * it should call $context->markHandled() to prevent further stages.
     */
    public function process(MessageProcessingContext $context): MessageProcessingContext;
}

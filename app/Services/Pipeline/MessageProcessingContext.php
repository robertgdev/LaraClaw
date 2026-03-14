<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\DTOs\AgentRoutingDTO;
use App\DTOs\IntentClassificationDTO;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Team;
use App\TypedCollections\AgentCollection;
use App\TypedCollections\TeamCollection;

/**
 * MessageProcessingContext - Carries state through the message processing pipeline.
 *
 * Each pipeline stage reads and/or writes to this context object,
 * building up the information needed to process a message.
 */
class MessageProcessingContext
{
    // Input
    public ConversationMessage $message;

    /** @var array<string, mixed> */
    public array $messageData = [];

    public bool $isInternal = false;

    // Configuration
    public int $maxConversationMessages = 50;

    public int $longResponseThreshold = 4000;

    // Routing
    public string $agentId = '';

    public ?Agent $agent = null;

    public string $processedMessage = '';

    public bool $isTeamRouted = false;

    public ?AgentRoutingDTO $routing = null;

    public AgentCollection $agents;

    public TeamCollection $teams;

    // Intent classification
    public ?IntentClassificationDTO $classification = null;

    public ?string $directExecutionResult = null;

    // Team context
    public ?Team $teamContext = null;

    // Session
    public ?Conversation $session = null;

    public bool $shouldReset = false;

    // Response
    public string $response = '';

    // Control flow
    public bool $handled = false;

    public ?string $earlyResponse = null;

    public function __construct(ConversationMessage $message)
    {
        $this->message = $message;
        $this->messageData = $message->toMessageData();
        $this->isInternal = ! empty($this->messageData['isInternal']);
        $this->processedMessage = $message->message;
        $this->maxConversationMessages = (int) config('laraclaw.conversation.max_messages', 50);
        $this->longResponseThreshold = (int) config('laraclaw.conversation.long_response_threshold', 4000);
    }

    /**
     * Mark the context as fully handled (no further processing needed).
     */
    public function markHandled(?string $response = null): void
    {
        $this->handled = true;
        $this->earlyResponse = $response;
    }

    /**
     * Check if processing should continue.
     */
    public function shouldContinue(): bool
    {
        return ! $this->handled;
    }
}

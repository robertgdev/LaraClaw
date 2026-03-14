<?php

declare(strict_types=1);

namespace App\Services\Commands;

use App\DTOs\CommandResponseDTO;
use App\Enums\FeedbackEnum;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use function Safe\json_decode;

/**
 * Handles JSON messages from the WebSocket web client.
 *
 * Supports message types: auth, message_send, sessions_list,
 * session_create, session_rename, session_delete, session_pin,
 * history_get, feedback_message, feedback_conversation, ping.
 */
class WebSocketMessageHandler
{
    public function __construct(
        protected SlashCommandHandler $slashHandler,
        protected \App\Services\ConversationHistoryService $chatHistoryService,
        protected \App\Services\CommandProcessingService $commandService
    ) {}

    /**
     * Handle JSON messages from the web client.
     *
     * @param  array<string, mixed>  $context
     */
    public function handle(string $message, array $context = []): CommandResponseDTO
    {
        $data = json_decode($message, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return CommandResponseDTO::error('Invalid JSON: '.json_last_error_msg(), 400);
        }

        $type = $data['type'] ?? null;

        if (! $type) {
            return CommandResponseDTO::error('Missing message type', 400);
        }

        return match ($type) {
            'auth' => $this->handleAuth($data, $context),
            'message_send' => $this->handleSendMessage($data, $context),
            'sessions_list' => $this->handleSessionsList($data, $context),
            'session_create' => $this->handleSessionCreate($data, $context),
            'session_rename' => $this->handleSessionRename($data, $context),
            'session_delete' => $this->handleSessionDelete($data, $context),
            'session_pin' => $this->handleSessionPin($data, $context),
            'history_get' => $this->handleHistoryGet($data, $context),
            'feedback_message' => $this->handleMessageFeedback($data, $context),
            'feedback_conversation' => $this->handleConversationFeedback($data, $context),
            'ping' => CommandResponseDTO::pong(),
            default => CommandResponseDTO::error("Unknown message type: {$type}", 400),
        };
    }

    /**
     * Handle authentication.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     */
    protected function handleAuth(array $data, array $context): CommandResponseDTO
    {
        $token = $data['token'] ?? null;
        $configuredToken = config('laraclaw.auth_token');

        if (! $configuredToken || $token === $configuredToken) {
            return new CommandResponseDTO(
                type: 'auth_success',
                message: 'Authentication successful',
                data: ['authenticated' => true],
                code: 200,
                success: true
            );
        }

        return new CommandResponseDTO(
            type: 'auth_failed',
            message: 'Invalid authentication token',
            data: ['authenticated' => false],
            code: 401,
            success: false
        );
    }

    /**
     * Handle web client message send.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     */
    protected function handleSendMessage(array $data, array $context): CommandResponseDTO
    {
        $message = $data['message'] ?? null;

        if (! $message) {
            return CommandResponseDTO::error('Message is required', 400);
        }

        $agentId = $data['agent_id'] ?? null;
        $sessionKey = $data['sessionKey'] ?? null;
        $friendlyId = $data['friendlyId'] ?? null;

        $conversationId = null;
        if ($sessionKey && trim($sessionKey) !== '') {
            $conversationId = trim($sessionKey);
        } elseif ($friendlyId && trim($friendlyId) !== '') {
            $conversationId = trim($friendlyId);
        }

        return $this->commandService->sendMessageToAgent($message, $agentId, $conversationId);
    }

    /**
     * Handle sessions list request.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     */
    protected function handleSessionsList(array $data, array $context): CommandResponseDTO
    {
        return new CommandResponseDTO(
            type: 'sessions',
            message: 'Sessions retrieved',
            data: ['sessions' => []],
            code: 200,
            success: true
        );
    }

    /**
     * Handle session create request.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     */
    protected function handleSessionCreate(array $data, array $context): CommandResponseDTO
    {
        $friendlyId = \Illuminate\Support\Str::uuid()->toString();

        return new CommandResponseDTO(
            type: 'session_created',
            message: 'Session created',
            data: [
                'sessionKey' => $friendlyId,
                'friendlyId' => $friendlyId,
            ],
            code: 200,
            success: true
        );
    }

    /**
     * Handle session rename request.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     */
    protected function handleSessionRename(array $data, array $context): CommandResponseDTO
    {
        return new CommandResponseDTO(
            type: 'session_renamed',
            message: 'Session renamed',
            data: ['success' => true],
            code: 200,
            success: true
        );
    }

    /**
     * Handle session delete request.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     */
    protected function handleSessionDelete(array $data, array $context): CommandResponseDTO
    {
        return new CommandResponseDTO(
            type: 'session_deleted',
            message: 'Session deleted',
            data: ['success' => true],
            code: 200,
            success: true
        );
    }

    /**
     * Handle session pin request.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     */
    protected function handleSessionPin(array $data, array $context): CommandResponseDTO
    {
        return new CommandResponseDTO(
            type: 'session_pinned',
            message: 'Session pin toggled',
            data: ['success' => true],
            code: 200,
            success: true
        );
    }

    /**
     * Handle history get request.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     */
    protected function handleHistoryGet(array $data, array $context): CommandResponseDTO
    {
        $friendlyId = $data['friendlyId'] ?? null;
        $history = $this->chatHistoryService->getRecentHistory(50);

        return new CommandResponseDTO(
            type: 'history',
            message: 'History retrieved',
            data: [
                'friendlyId' => $friendlyId,
                'messages' => $history,
            ],
            code: 200,
            success: true
        );
    }

    /**
     * Handle message feedback request.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     */
    protected function handleMessageFeedback(array $data, array $context): CommandResponseDTO
    {
        $messageId = $data['message_id'] ?? null;
        $feedbackValue = $data['feedback'] ?? null;
        $comment = $data['comment'] ?? null;

        if (! $messageId) {
            return CommandResponseDTO::error('Message ID is required', 400);
        }

        if ($feedbackValue === null) {
            return CommandResponseDTO::error('Feedback value is required', 400);
        }

        // Find the message
        $message = ConversationMessage::where('message_id', $messageId)->first();

        if (! $message) {
            return CommandResponseDTO::error('Message not found', 404);
        }

        // Convert feedback value to enum
        $feedback = FeedbackEnum::fromInt((int) $feedbackValue);

        if (! $feedback) {
            return CommandResponseDTO::error('Invalid feedback value. Must be -1, 0, or 1', 400);
        }

        // Set the feedback
        $message->setFeedback($feedback, $comment);

        return new CommandResponseDTO(
            type: 'feedback_message_saved',
            message: 'Message feedback saved',
            data: [
                'success' => true,
                'message_id' => $messageId,
                'feedback' => $feedback->value,
                'feedback_label' => $feedback->label(),
            ],
            code: 200,
            success: true
        );
    }

    /**
     * Handle conversation feedback request.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     */
    protected function handleConversationFeedback(array $data, array $context): CommandResponseDTO
    {
        $conversationId = $data['conversation_id'] ?? null;
        $feedbackValue = $data['feedback'] ?? null;
        $comment = $data['comment'] ?? null;

        if (! $conversationId) {
            return CommandResponseDTO::error('Conversation ID is required', 400);
        }

        if ($feedbackValue === null) {
            return CommandResponseDTO::error('Feedback value is required', 400);
        }

        // Find the conversation
        $conversation = Conversation::where('conversation_id', $conversationId)->first();

        if (! $conversation) {
            return CommandResponseDTO::error('Conversation not found', 404);
        }

        // Convert feedback value to enum
        $feedback = FeedbackEnum::fromInt((int) $feedbackValue);

        if (! $feedback) {
            return CommandResponseDTO::error('Invalid feedback value. Must be -1, 0, or 1', 400);
        }

        // Set the feedback
        $conversation->setFeedback($feedback, $comment);

        return new CommandResponseDTO(
            type: 'feedback_conversation_saved',
            message: 'Conversation feedback saved',
            data: [
                'success' => true,
                'conversation_id' => $conversationId,
                'feedback' => $feedback->value,
                'feedback_label' => $feedback->label(),
            ],
            code: 200,
            success: true
        );
    }
}

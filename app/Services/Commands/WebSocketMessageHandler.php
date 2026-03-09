<?php

declare(strict_types=1);

namespace App\Services\Commands;

use App\DTOs\CommandResponseDTO;

/**
 * Handles JSON messages from the WebSocket web client.
 *
 * Supports message types: auth, message_send, sessions_list,
 * session_create, session_rename, session_delete, session_pin,
 * history_get, ping.
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
            'ping' => CommandResponseDTO::pong(),
            default => CommandResponseDTO::error("Unknown message type: {$type}", 400),
        };
    }

    /**
     * Handle authentication.
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
}

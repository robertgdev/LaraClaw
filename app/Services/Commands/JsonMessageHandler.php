<?php

declare(strict_types=1);

namespace App\Services\Commands;

use App\DTOs\CommandResponseDTO;
use App\Services\ConversationHistoryService;
use Illuminate\Support\Str;

/**
 * Handles JSON-formatted messages from web clients via WebSocket.
 *
 * Dispatches typed JSON messages (auth, message_send, session CRUD, etc.)
 * to their respective handlers and returns CommandResponseDTO results.
 *
 * Extracted from CommandProcessingService to separate transport-level
 * concerns from business logic.
 */
class JsonMessageHandler
{
    protected ConversationHistoryService $chatHistoryService;

    public function __construct(ConversationHistoryService $chatHistoryService)
    {
        $this->chatHistoryService = $chatHistoryService;
    }

    /**
     * Handle a parsed JSON message.
     *
     * @param  array<string, mixed>  $data  The decoded JSON data
     * @param  array<string, mixed>  $context  Optional context (server status, etc.)
     * @param  callable(string $message, ?string $agentId, ?string $conversationId): CommandResponseDTO  $sendToAgent  Callback to route messages to agents
     */
    public function handle(array $data, array $context, callable $sendToAgent): CommandResponseDTO
    {
        $type = $data['type'] ?? null;

        if (! $type) {
            return CommandResponseDTO::error('Missing message type', 400);
        }

        return match ($type) {
            'auth' => $this->handleAuth($data),
            'message_send' => $this->handleMessageSend($data, $sendToAgent),
            'sessions_list' => $this->handleSessionsList(),
            'session_create' => $this->handleSessionCreate(),
            'session_rename' => $this->handleSessionRename(),
            'session_delete' => $this->handleSessionDelete(),
            'session_pin' => $this->handleSessionPin(),
            'history_get' => $this->handleHistoryGet($data),
            'ping' => CommandResponseDTO::pong(),
            default => CommandResponseDTO::error("Unknown message type: {$type}", 400),
        };
    }

    /**
     * Handle authentication.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleAuth(array $data): CommandResponseDTO
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
     * @param  callable(string $message, ?string $agentId, ?string $conversationId): CommandResponseDTO  $sendToAgent
     */
    protected function handleMessageSend(array $data, callable $sendToAgent): CommandResponseDTO
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

        return $sendToAgent($message, $agentId, $conversationId);
    }

    /**
     * Handle sessions list request.
     */
    protected function handleSessionsList(): CommandResponseDTO
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
    protected function handleSessionCreate(): CommandResponseDTO
    {
        $friendlyId = Str::uuid()->toString();

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
    protected function handleSessionRename(): CommandResponseDTO
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
    protected function handleSessionDelete(): CommandResponseDTO
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
    protected function handleSessionPin(): CommandResponseDTO
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
     */
    protected function handleHistoryGet(array $data): CommandResponseDTO
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

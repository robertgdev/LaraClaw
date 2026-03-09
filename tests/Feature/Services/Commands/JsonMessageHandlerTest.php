<?php

use App\DTOs\CommandResponseDTO;
use App\Services\Commands\JsonMessageHandler;
use App\Services\ConversationHistoryService;

describe('JsonMessageHandler', function () {
    beforeEach(function () {
        $this->chatHistory = Mockery::mock(ConversationHistoryService::class);
        $this->handler = new JsonMessageHandler($this->chatHistory);
        $this->sendToAgent = fn (string $msg, ?string $agentId, ?string $convId) => CommandResponseDTO::agentResponse(
            $agentId ?? 'default',
            'Test Agent',
            "Response to: {$msg}",
            'test',
            'test-model',
            $convId
        );
    });

    it('returns error for missing message type', function () {
        $result = $this->handler->handle([], [], $this->sendToAgent);

        expect($result->success)->toBeFalse()
            ->and($result->message)->toContain('Missing message type');
    });

    it('returns error for unknown message type', function () {
        $result = $this->handler->handle(['type' => 'unknown_type'], [], $this->sendToAgent);

        expect($result->success)->toBeFalse()
            ->and($result->message)->toContain('Unknown message type');
    });

    it('handles ping messages', function () {
        $result = $this->handler->handle(['type' => 'ping'], [], $this->sendToAgent);

        expect($result->success)->toBeTrue()
            ->and($result->type)->toBe('pong');
    });

    it('handles auth with no configured token', function () {
        config(['laraclaw.auth_token' => null]);
        $result = $this->handler->handle(['type' => 'auth'], [], $this->sendToAgent);

        expect($result->success)->toBeTrue()
            ->and($result->type)->toBe('auth_success');
    });

    it('handles session create', function () {
        $result = $this->handler->handle(['type' => 'session_create'], [], $this->sendToAgent);

        expect($result->success)->toBeTrue()
            ->and($result->type)->toBe('session_created')
            ->and($result->data['sessionKey'])->not->toBeNull();
    });

    it('handles session rename', function () {
        $result = $this->handler->handle(['type' => 'session_rename'], [], $this->sendToAgent);

        expect($result->success)->toBeTrue()
            ->and($result->type)->toBe('session_renamed');
    });

    it('handles session delete', function () {
        $result = $this->handler->handle(['type' => 'session_delete'], [], $this->sendToAgent);

        expect($result->success)->toBeTrue()
            ->and($result->type)->toBe('session_deleted');
    });

    it('handles session pin', function () {
        $result = $this->handler->handle(['type' => 'session_pin'], [], $this->sendToAgent);

        expect($result->success)->toBeTrue()
            ->and($result->type)->toBe('session_pinned');
    });

    it('handles message_send without message', function () {
        $result = $this->handler->handle(['type' => 'message_send'], [], $this->sendToAgent);

        expect($result->success)->toBeFalse()
            ->and($result->message)->toContain('Message is required');
    });

    it('handles message_send with message and agent', function () {
        $result = $this->handler->handle(
            ['type' => 'message_send', 'message' => 'hello', 'agent_id' => 'assistant'],
            [],
            $this->sendToAgent
        );

        expect($result->success)->toBeTrue()
            ->and($result->message)->toContain('Response to: hello');
    });

    it('handles history_get', function () {
        $this->chatHistory->shouldReceive('getRecentHistory')->with(50)->andReturn([]);

        $result = $this->handler->handle(
            ['type' => 'history_get', 'friendlyId' => 'test-id'],
            [],
            $this->sendToAgent
        );

        expect($result->success)->toBeTrue()
            ->and($result->type)->toBe('history')
            ->and($result->data['friendlyId'])->toBe('test-id');
    });

    it('handles sessions_list', function () {
        $result = $this->handler->handle(['type' => 'sessions_list'], [], $this->sendToAgent);

        expect($result->success)->toBeTrue()
            ->and($result->type)->toBe('sessions');
    });
});

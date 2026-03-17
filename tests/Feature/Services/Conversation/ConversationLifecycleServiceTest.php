<?php

use App\Enums\ChannelEnum;
use App\Models\ContextItem;
use App\Models\Conversation;
use App\Services\Conversation\ConversationLifecycleService;
use App\Services\MemoryEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new ConversationLifecycleService;
    $this->memoryService = app(MemoryEngineService::class);
});

describe('ConversationLifecycleService', function () {
    describe('findOrCreate', function () {
        it('creates a new conversation when no ID provided', function () {
            $conversation = $this->service->findOrCreate(
                null,
                ChannelEnum::WEBSOCKET,
                'user',
                'ws_test123',
                'Hello world'
            );

            expect($conversation)->toBeInstanceOf(Conversation::class)
                ->and($conversation->channel)->toBe(ChannelEnum::WEBSOCKET)
                ->and($conversation->sender)->toBe('user')
                ->and($conversation->sender_id)->toBe('ws_test123')
                ->and($conversation->derived_title)->toBe('Hello world');
        });

        it('creates a new conversation with generated sender_id', function () {
            $conversation = $this->service->findOrCreate(
                null,
                ChannelEnum::WEBSOCKET,
                'user',
                null,
                'Test message'
            );

            expect($conversation->sender_id)->toStartWith('ws_');
        });

        it('finds existing conversation by ID', function () {
            $existing = Conversation::create([
                'conversation_id' => 'test-conv-123',
                'channel' => ChannelEnum::WEBSOCKET,
                'sender' => 'user',
                'sender_id' => 'ws_test',
                'is_active' => true,
            ]);

            $found = $this->service->findOrCreate(
                'test-conv-123',
                ChannelEnum::WEBSOCKET
            );

            expect($found->id)->toBe($existing->id)
                ->and($found->conversation_id)->toBe('test-conv-123');
        });

        it('creates new conversation when ID not found', function () {
            $conversation = $this->service->findOrCreate(
                'nonexistent-id',
                ChannelEnum::CLI,
                'cli-user'
            );

            expect($conversation)->toBeInstanceOf(Conversation::class)
                ->and($conversation->conversation_id)->toBe('nonexistent-id');
        });

        it('updates sender_id on existing conversation if empty', function () {
            $existing = Conversation::create([
                'conversation_id' => 'test-conv-empty-sender',
                'channel' => ChannelEnum::WEBSOCKET,
                'sender' => 'user',
                'sender_id' => null,
                'is_active' => true,
            ]);

            $found = $this->service->findOrCreate(
                'test-conv-empty-sender',
                ChannelEnum::WEBSOCKET,
                'user',
                'new_sender_id'
            );

            expect($found->sender_id)->toBe('new_sender_id');
        });

        it('truncates long title hints', function () {
            $longMessage = str_repeat('a', 100);

            $conversation = $this->service->findOrCreate(
                null,
                ChannelEnum::WEBSOCKET,
                'user',
                null,
                $longMessage
            );

            expect(strlen($conversation->derived_title))->toBeLessThanOrEqual(53); // 50 + "..."
        });
    });

    describe('recordExchange', function () {
        it('records user message and agent response', function () {
            $conversation = Conversation::create([
                'conversation_id' => 'exchange-test',
                'channel' => ChannelEnum::WEBSOCKET,
                'sender' => 'user',
                'sender_id' => 'ws_test',
                'is_active' => true,
            ]);

            $this->service->recordExchange(
                $conversation,
                'Hello agent',
                'assistant',
                'Assistant',
                'Hello user!',
                'openai',
                'gpt-4'
            );

            $conversation->refresh();
            expect($conversation->total_messages)->toBe(2);
        });

        it('creates context items when memory service is injected', function () {
            $conversation = Conversation::create([
                'conversation_id' => 'context-item-test',
                'channel' => ChannelEnum::WEBSOCKET,
                'sender' => 'user',
                'sender_id' => 'ws_test',
                'is_active' => true,
            ]);

            // Inject memory service to enable context item creation
            $this->service->setMemoryService($this->memoryService);

            $this->service->recordExchange(
                $conversation,
                'Hello agent',
                'assistant',
                'Assistant',
                'Hello user!',
                'openai',
                'gpt-4'
            );

            $conversation->refresh();

            // Verify context items were created
            $contextItems = ContextItem::forConversation($conversation->id)->ordered()->get();
            expect($contextItems)->toHaveCount(2)
                ->and($contextItems[0]->item_type)->toBe('message')
                ->and($contextItems[0]->ordinal)->toBe(0)
                ->and($contextItems[1]->item_type)->toBe('message')
                ->and($contextItems[1]->ordinal)->toBe(1);
        });

        it('does not create context items when memory service is not injected', function () {
            $conversation = Conversation::create([
                'conversation_id' => 'no-context-item-test',
                'channel' => ChannelEnum::WEBSOCKET,
                'sender' => 'user',
                'sender_id' => 'ws_test',
                'is_active' => true,
            ]);

            // Do NOT inject memory service
            $this->service->recordExchange(
                $conversation,
                'Hello agent',
                'assistant',
                'Assistant',
                'Hello user!',
                'openai',
                'gpt-4'
            );

            $conversation->refresh();

            // Verify no context items were created
            $contextItems = ContextItem::forConversation($conversation->id)->get();
            expect($contextItems)->toHaveCount(0);
        });
    });
});

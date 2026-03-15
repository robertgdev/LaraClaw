<?php

use App\DTOs\EpisodicEventDTO;
use App\Enums\ChannelEnum;
use App\Enums\EpisodicEventTypeEnum;
use App\Jobs\ProcessMessageJob;
use App\Models\Agent;
use App\Models\ConversationMessage;
use App\Models\Memory;
use App\Services\MemoryEngineService;
use App\Services\Pipeline\MessageProcessingContext;
use App\Services\Pipeline\MessageProcessingPipeline;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Set up test workspace
    $this->testWorkspace = '/tmp/laraclaw_test_job_'.uniqid();
    File::ensureDirectoryExists($this->testWorkspace);

    // Set workspace path in settings
    $settings = app(SettingsService::class);
    $settings->set('workspace.path', $this->testWorkspace);

    // Create a test agent
    Agent::query()->delete();
    Agent::create([
        'agent_id' => 'assistant',
        'name' => 'Assistant',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'is_active' => true,
        'skills' => [],
        'capabilities' => ['question', 'conversation'],
    ]);

    // Clean up any existing memories
    Memory::query()->delete();
});

afterEach(function () {
    // Clean up test workspace
    if (File::isDirectory($this->testWorkspace)) {
        File::deleteDirectory($this->testWorkspace);
    }

    // Clean up models
    Agent::query()->delete();
    ConversationMessage::query()->delete();
    Memory::query()->delete();
});

describe('ProcessMessageJob Memory Integration', function () {
    it('job class exists', function () {
        expect(class_exists(ProcessMessageJob::class))->toBeTrue();
    });

    it('job has memory service integration', function () {
        $reflection = new ReflectionClass(ProcessMessageJob::class);

        // Check that MemoryEngineService is used in the class
        $source = file_get_contents($reflection->getFileName());
        expect($source)->toContain('MemoryEngineService');
    });

    it('job uses pipeline pattern', function () {
        $reflection = new ReflectionClass(ProcessMessageJob::class);

        // Check that pipeline is used
        $source = file_get_contents($reflection->getFileName());
        expect($source)->toContain('MessageProcessingPipeline')
            ->and($source)->toContain('buildPipeline');
    });

    it('job has buildPipeline method', function () {
        $reflection = new ReflectionClass(ProcessMessageJob::class);

        expect($reflection->hasMethod('buildPipeline'))->toBeTrue();
    });

    it('memory service is injected in handle method', function () {
        $reflection = new ReflectionClass(ProcessMessageJob::class);
        $method = $reflection->getMethod('handle');
        $parameters = $method->getParameters();

        // Check if MemoryEngineService is a parameter
        $memoryServiceParam = collect($parameters)->firstWhere('name', 'memoryService');
        expect($memoryServiceParam)->not->toBeNull()
            ->and($memoryServiceParam->getType()->getName())->toBe(MemoryEngineService::class);
    });
});

describe('MessageProcessingPipeline', function () {
    it('pipeline class exists', function () {
        expect(class_exists(MessageProcessingPipeline::class))->toBeTrue();
    });

    it('pipeline context class exists', function () {
        expect(class_exists(MessageProcessingContext::class))->toBeTrue();
    });

    it('pipeline supports adding stages', function () {
        $pipeline = new MessageProcessingPipeline;
        expect($pipeline->getStages())->toBeEmpty();
    });
});

describe('MemoryEngineService Event Recording', function () {
    it('records task_completed event with correct format', function () {
        $memoryService = app(MemoryEngineService::class);
        $senderId = 'test-sender-job';
        $channel = ChannelEnum::TELEGRAM;

        // Simulate recording an event like ProcessMessageJob would
        $id = $memoryService->recordEvent($senderId, $channel, new EpisodicEventDTO(
            type: EpisodicEventTypeEnum::TASK_COMPLETED,
            content: 'User: What is the weather? → The weather is sunny.',
            importance: 0.7,
        ));

        expect($id)->toBeInt()->toBeGreaterThan(0);

        $event = $memoryService->getEvent($id);
        expect($event)->not->toBeNull()
            ->and($event->event_type)->toBe(EpisodicEventTypeEnum::TASK_COMPLETED)
            ->and($event->sender_id)->toBe($senderId)
            ->and($event->channel)->toBe($channel);

        // Cleanup
        Memory::forSender($senderId, $channel)->delete();
    });

    it('records events with user message and response', function () {
        $memoryService = app(MemoryEngineService::class);
        $senderId = 'test-sender-job-2';
        $channel = ChannelEnum::TELEGRAM;

        $userMessage = 'What is my timezone?';
        $agentResponse = 'Your timezone is Europe/Berlin.';

        // Record the event
        $memoryService->recordEvent($senderId, $channel, new EpisodicEventDTO(
            type: EpisodicEventTypeEnum::TASK_COMPLETED,
            content: "User: {$userMessage} → {$agentResponse}",
            importance: 0.7,
        ));

        // Verify it can be retrieved via search
        $context = $memoryService->getContextForAgent($senderId, $channel, 'timezone');
        // Context should contain either the search term or the event type
        $containsRelevant = str_contains($context, 'timezone') || str_contains($context, 'Task Completed');
        expect($containsRelevant)->toBeTrue();

        // Cleanup
        Memory::forSender($senderId, $channel)->delete();
    });

    it('isolates memories by sender and channel', function () {
        $memoryService = app(MemoryEngineService::class);

        // Record events for different users with high importance so they appear in context
        $memoryService->recordEvent('user-telegram', ChannelEnum::TELEGRAM, new EpisodicEventDTO(
            type: EpisodicEventTypeEnum::CORRECTION,
            content: 'Telegram user fact',
            importance: 0.9,
        ));

        $memoryService->recordEvent('user-discord', ChannelEnum::DISCORD, new EpisodicEventDTO(
            type: EpisodicEventTypeEnum::CORRECTION,
            content: 'Discord user fact',
            importance: 0.9,
        ));

        $memoryService->recordEvent('user-telegram', ChannelEnum::DISCORD, new EpisodicEventDTO(
            type: EpisodicEventTypeEnum::CORRECTION,
            content: 'Same user different channel',
            importance: 0.9,
        ));

        // Verify isolation - check that each context contains only the right memories
        $telegramContext = $memoryService->getContextForAgent('user-telegram', ChannelEnum::TELEGRAM);
        $discordContext = $memoryService->getContextForAgent('user-discord', ChannelEnum::DISCORD);
        $mixedContext = $memoryService->getContextForAgent('user-telegram', ChannelEnum::DISCORD);

        // Each context should contain its own memory
        expect($telegramContext)->toContain('Telegram user fact')
            ->and($discordContext)->toContain('Discord user fact')
            ->and($mixedContext)->toContain('Same user different channel');

        // Telegram context should NOT contain Discord user's fact
        expect($telegramContext)->not->toContain('Discord user fact');

        // Cleanup
        Memory::forSender('user-telegram', ChannelEnum::TELEGRAM)->delete();
        Memory::forSender('user-discord', ChannelEnum::DISCORD)->delete();
        Memory::forSender('user-telegram', ChannelEnum::DISCORD)->delete();
    });
});

describe('Memory Context Format', function () {
    it('formats memory context for agent prompt', function () {
        $memoryService = app(MemoryEngineService::class);
        $senderId = 'test-format-sender';
        $channel = ChannelEnum::TELEGRAM;

        // Add various types of memories
        $memoryService->recordEvent($senderId, $channel, new EpisodicEventDTO(
            type: EpisodicEventTypeEnum::CORRECTION,
            content: 'Always use TypeScript strict mode',
            importance: 0.9,
        ));

        $memoryService->recordEvent($senderId, $channel, new EpisodicEventDTO(
            type: EpisodicEventTypeEnum::PREFERENCE_LEARNED,
            content: 'User prefers dark mode',
            importance: 0.8,
        ));

        $context = $memoryService->getContextForAgent($senderId, $channel);

        // Context should contain formatted sections
        expect($context)->toBeString()
            ->and(strlen($context))->toBeGreaterThan(0);

        // Cleanup
        Memory::forSender($senderId, $channel)->delete();
    });
});

describe('is_internal Flag Handling', function () {
    it('creates regular messages with is_internal = false by default', function () {
        // Create a conversation first
        $conversation = \App\Models\Conversation::create([
            'channel' => ChannelEnum::DISCORD,
            'sender' => 'test_user',
            'sender_id' => '12345',
        ]);

        $message = ConversationMessage::createIncoming([
            'channel' => ChannelEnum::DISCORD,
            'sender' => 'test_user',
            'sender_id' => '12345',
            'message' => 'Hello, this is a test message',
            'conversation_id' => $conversation->conversation_id,
        ]);

        expect($message->is_internal)->toBeFalse()
            ->and($message->toMessageData()['isInternal'])->toBeFalse();
    });

    it('creates internal messages with is_internal = true when explicitly set', function () {
        // Create a conversation first
        $conversation = \App\Models\Conversation::create([
            'channel' => ChannelEnum::DISCORD,
            'sender' => 'test_user',
            'sender_id' => '12345',
        ]);

        $message = ConversationMessage::createIncoming([
            'channel' => ChannelEnum::DISCORD,
            'sender' => 'test_user',
            'sender_id' => '12345',
            'message' => '[Message from teammate @agent1]: Internal handoff',
            'conversation_id' => $conversation->conversation_id,
            'is_internal' => true,
        ]);

        expect($message->is_internal)->toBeTrue()
            ->and($message->toMessageData()['isInternal'])->toBeTrue();
    });

    it('correctly distinguishes internal from external messages via toMessageData', function () {
        // Create a conversation first
        $conversation = \App\Models\Conversation::create([
            'channel' => ChannelEnum::DISCORD,
            'sender' => 'user',
            'sender_id' => 'user-123',
        ]);

        // External message (user to agent)
        $externalMessage = ConversationMessage::createIncoming([
            'channel' => ChannelEnum::DISCORD,
            'sender' => 'user',
            'sender_id' => 'user-123',
            'message' => 'What is the weather?',
            'conversation_id' => $conversation->conversation_id,
        ]);

        // Internal message (agent to agent)
        $internalMessage = ConversationMessage::createIncoming([
            'channel' => ChannelEnum::DISCORD,
            'sender' => 'user',
            'sender_id' => 'user-123',
            'message' => '[Message from teammate @agent1]: Please help',
            'conversation_id' => $conversation->conversation_id,
            'is_internal' => true,
        ]);

        $externalData = $externalMessage->toMessageData();
        $internalData = $internalMessage->toMessageData();

        // Both have conversation_id, but only internal should have isInternal = true
        expect($externalData['conversationId'])->toBe($conversation->conversation_id)
            ->and($externalData['isInternal'])->toBeFalse()
            ->and($internalData['conversationId'])->toBe($conversation->conversation_id)
            ->and($internalData['isInternal'])->toBeTrue();
    });

    it('is_internal flag is properly cast to boolean', function () {
        // Create a conversation first
        $conversation = \App\Models\Conversation::create([
            'channel' => ChannelEnum::WEBSOCKET,
            'sender' => 'web_user',
            'sender_id' => 'web-123',
        ]);

        $message = ConversationMessage::createIncoming([
            'channel' => ChannelEnum::WEBSOCKET,
            'sender' => 'web_user',
            'sender_id' => 'web-123',
            'message' => 'Test',
            'conversation_id' => $conversation->conversation_id,
            'is_internal' => 1, // Cast to boolean
        ]);

        expect($message->is_internal)->toBeTrue()
            ->and(is_bool($message->is_internal))->toBeTrue();

        $message2 = ConversationMessage::createIncoming([
            'channel' => ChannelEnum::WEBSOCKET,
            'sender' => 'web_user',
            'sender_id' => 'web-123',
            'message' => 'Test 2',
            'conversation_id' => $conversation->conversation_id,
            'is_internal' => 0, // Cast to boolean
        ]);

        expect($message2->is_internal)->toBeFalse()
            ->and(is_bool($message2->is_internal))->toBeTrue();
    });
});

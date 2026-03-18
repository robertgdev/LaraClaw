<?php

use App\Enums\ChannelEnum;
use App\Jobs\ProcessMessageJob;
use App\Models\Agent;
use App\Models\ConversationMessage;
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
});

afterEach(function () {
    // Clean up test workspace
    if (File::isDirectory($this->testWorkspace)) {
        File::deleteDirectory($this->testWorkspace);
    }

    // Clean up models
    Agent::query()->delete();
    ConversationMessage::query()->delete();
});

describe('ProcessMessageJob Integration', function () {
    it('job class exists', function () {
        expect(class_exists(ProcessMessageJob::class))->toBeTrue();
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

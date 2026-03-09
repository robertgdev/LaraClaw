<?php

use App\Enums\ChannelEnum;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Pipeline\MessagePipelineStage;
use App\Services\Pipeline\MessageProcessingContext;
use App\Services\Pipeline\MessageProcessingPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Helper class to track stage execution order
class TrackableStage implements MessagePipelineStage
{
    public static array $executionOrder = [];

    private string $name;

    private bool $shouldHandle;

    public function __construct(string $name, bool $shouldHandle = false)
    {
        $this->name = $name;
        $this->shouldHandle = $shouldHandle;
    }

    public function process(MessageProcessingContext $context): MessageProcessingContext
    {
        self::$executionOrder[] = $this->name;
        if ($this->shouldHandle) {
            $context->markHandled('early response');
        }

        return $context;
    }

    public static function reset(): void
    {
        self::$executionOrder = [];
    }
}

describe('MessageProcessingPipeline', function () {
    beforeEach(function () {
        TrackableStage::reset();
        // Create a conversation for test messages
        $this->conversation = Conversation::create([
            'channel' => ChannelEnum::DISCORD,
            'sender' => 'test_user',
            'sender_id' => 'test-sender-123',
        ]);
    });

    describe('pipeline execution', function () {
        it('runs stages in order', function () {
            $message = ConversationMessage::createIncoming([
                'channel' => ChannelEnum::DISCORD,
                'sender' => 'user',
                'sender_id' => 'test-sender-123',
                'message' => 'hello',
                'conversation_id' => $this->conversation->conversation_id,
            ]);

            $pipeline = new MessageProcessingPipeline;
            $pipeline->addStage(new TrackableStage('stage1'));
            $pipeline->addStage(new TrackableStage('stage2'));
            $pipeline->run($message);

            expect(TrackableStage::$executionOrder)->toBe(['stage1', 'stage2']);
        });

        it('stops when context is marked as handled', function () {
            $message = ConversationMessage::createIncoming([
                'channel' => ChannelEnum::DISCORD,
                'sender' => 'user',
                'sender_id' => 'test-sender-123',
                'message' => 'hello',
                'conversation_id' => $this->conversation->conversation_id,
            ]);

            $pipeline = new MessageProcessingPipeline;
            $pipeline->addStage(new TrackableStage('stage1', shouldHandle: true));
            $pipeline->addStage(new TrackableStage('stage2'));
            $result = $pipeline->run($message);

            expect(TrackableStage::$executionOrder)->toBe(['stage1'])
                ->and($result->handled)->toBeTrue()
                ->and($result->earlyResponse)->toBe('early response');
        });

        it('returns context with correct initial state', function () {
            $message = ConversationMessage::createIncoming([
                'channel' => ChannelEnum::DISCORD,
                'sender' => 'user',
                'sender_id' => 'test-sender-123',
                'message' => 'test message',
                'conversation_id' => $this->conversation->conversation_id,
            ]);

            $pipeline = new MessageProcessingPipeline;
            $result = $pipeline->run($message);

            expect($result)->toBeInstanceOf(MessageProcessingContext::class)
                ->and($result->processedMessage)->toBe('test message')
                ->and($result->isInternal)->toBeFalse()
                ->and($result->handled)->toBeFalse();
        });
    });

    describe('stage management', function () {
        it('starts with no stages', function () {
            $pipeline = new MessageProcessingPipeline;
            expect($pipeline->getStages())->toBeEmpty();
        });

        it('adds stages and returns self', function () {
            $stage = new TrackableStage('test');

            $pipeline = new MessageProcessingPipeline;
            $result = $pipeline->addStage($stage);

            expect($result)->toBe($pipeline)
                ->and($pipeline->getStages())->toHaveCount(1);
        });

        it('supports multiple stages', function () {
            $pipeline = new MessageProcessingPipeline;
            $pipeline->addStage(new TrackableStage('a'));
            $pipeline->addStage(new TrackableStage('b'));
            $pipeline->addStage(new TrackableStage('c'));

            expect($pipeline->getStages())->toHaveCount(3);
        });
    });
});

describe('MessageProcessingContext', function () {
    beforeEach(function () {
        $this->conversation = Conversation::create([
            'channel' => ChannelEnum::WEBSOCKET,
            'sender' => 'test_user',
            'sender_id' => 'ws-test-123',
        ]);
    });

    it('initializes from a ConversationMessage', function () {
        $message = ConversationMessage::createIncoming([
            'channel' => ChannelEnum::WEBSOCKET,
            'sender' => 'user',
            'sender_id' => 'ws-test-123',
            'message' => 'hello world',
            'conversation_id' => $this->conversation->conversation_id,
        ]);

        $context = new MessageProcessingContext($message);

        expect($context->processedMessage)->toBe('hello world')
            ->and($context->isInternal)->toBeFalse()
            ->and($context->handled)->toBeFalse()
            ->and($context->shouldContinue())->toBeTrue();
    });

    it('can be marked as handled', function () {
        $message = ConversationMessage::createIncoming([
            'channel' => ChannelEnum::WEBSOCKET,
            'sender' => 'user',
            'sender_id' => 'ws-test-123',
            'message' => 'test',
            'conversation_id' => $this->conversation->conversation_id,
        ]);

        $context = new MessageProcessingContext($message);
        $context->markHandled('done');

        expect($context->handled)->toBeTrue()
            ->and($context->shouldContinue())->toBeFalse()
            ->and($context->earlyResponse)->toBe('done');
    });

    it('detects internal messages', function () {
        $message = ConversationMessage::createIncoming([
            'channel' => ChannelEnum::WEBSOCKET,
            'sender' => 'agent',
            'sender_id' => 'ws-test-123',
            'message' => 'internal message',
            'conversation_id' => $this->conversation->conversation_id,
            'is_internal' => true,
        ]);

        $context = new MessageProcessingContext($message);

        expect($context->isInternal)->toBeTrue();
    });
});

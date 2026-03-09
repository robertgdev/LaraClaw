<?php

use App\Enums\ChannelEnum;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('ConversationMessage reply_to', function () {
    beforeEach(function () {
        $this->conversation = Conversation::create([
            'conversation_id' => 'test-reply-' . uniqid(),
            'channel' => ChannelEnum::WEBSOCKET,
            'sender' => 'user',
            'sender_id' => 'ws_test',
            'is_active' => true,
        ]);
    });

    describe('reply_to field can be set', function () {
        it('creates an incoming message without reply_to', function () {
            $message = ConversationMessage::createIncoming([
                'conversation_id' => $this->conversation->conversation_id,
                'channel' => ChannelEnum::WEBSOCKET,
                'sender' => 'user',
                'sender_id' => 'ws_test',
                'message' => 'Hello, agent!',
            ]);

            expect($message->reply_to)->toBeNull();
        });

        it('creates an outgoing message without reply_to', function () {
            $message = ConversationMessage::createOutgoing([
                'conversation_id' => $this->conversation->conversation_id,
                'channel' => ChannelEnum::WEBSOCKET,
                'sender' => 'Agent',
                'message' => 'Hello, user!',
            ]);

            expect($message->reply_to)->toBeNull();
        });

        it('creates an incoming message with reply_to set', function () {
            $originalMessage = ConversationMessage::createIncoming([
                'conversation_id' => $this->conversation->conversation_id,
                'channel' => ChannelEnum::WEBSOCKET,
                'sender' => 'user',
                'message' => 'First message',
            ]);

            $replyMessage = ConversationMessage::createIncoming([
                'conversation_id' => $this->conversation->conversation_id,
                'channel' => ChannelEnum::WEBSOCKET,
                'sender' => 'user2',
                'message' => 'Reply to first',
                'reply_to' => $originalMessage->id,
            ]);

            expect($replyMessage->reply_to)->toBe($originalMessage->id);
        });

        it('creates an outgoing message with reply_to set', function () {
            $incomingMessage = ConversationMessage::createIncoming([
                'conversation_id' => $this->conversation->conversation_id,
                'channel' => ChannelEnum::WEBSOCKET,
                'sender' => 'user',
                'message' => 'Hello agent!',
            ]);

            $outgoingMessage = ConversationMessage::createOutgoing([
                'conversation_id' => $this->conversation->conversation_id,
                'channel' => ChannelEnum::WEBSOCKET,
                'sender' => 'Agent',
                'message' => 'Hello user!',
                'reply_to' => $incomingMessage->id,
                'provider' => 'anthropic',
                'model' => 'claude-3-opus',
            ]);

            expect($outgoingMessage->reply_to)->toBe($incomingMessage->id)
                ->and($outgoingMessage->provider)->toBe('anthropic')
                ->and($outgoingMessage->model)->toBe('claude-3-opus');
        });
    });

    describe('replyTo relationship', function () {
        it('retrieves the message being replied to', function () {
            $originalMessage = ConversationMessage::createIncoming([
                'conversation_id' => $this->conversation->conversation_id,
                'channel' => ChannelEnum::WEBSOCKET,
                'sender' => 'user',
                'message' => 'Original message',
            ]);

            $replyMessage = ConversationMessage::createOutgoing([
                'conversation_id' => $this->conversation->conversation_id,
                'channel' => ChannelEnum::WEBSOCKET,
                'sender' => 'Agent',
                'message' => 'Reply message',
                'reply_to' => $originalMessage->id,
            ]);

            $replyTo = $replyMessage->replyTo;

            expect($replyTo)->toBeInstanceOf(ConversationMessage::class)
                ->and($replyTo->id)->toBe($originalMessage->id)
                ->and($replyTo->message)->toBe('Original message');
        });

        it('returns null when reply_to is null', function () {
            $message = ConversationMessage::createOutgoing([
                'conversation_id' => $this->conversation->conversation_id,
                'channel' => ChannelEnum::WEBSOCKET,
                'sender' => 'Agent',
                'message' => 'No reply target',
            ]);

            expect($message->replyTo)->toBeNull();
        });
    });

    describe('replies relationship', function () {
        it('retrieves all replies to a message', function () {
            $originalMessage = ConversationMessage::createIncoming([
                'conversation_id' => $this->conversation->conversation_id,
                'channel' => ChannelEnum::WEBSOCKET,
                'sender' => 'user',
                'message' => 'Original message',
            ]);

            // Create multiple replies
            ConversationMessage::createOutgoing([
                'conversation_id' => $this->conversation->conversation_id,
                'channel' => ChannelEnum::WEBSOCKET,
                'sender' => 'Agent1',
                'message' => 'Reply 1',
                'reply_to' => $originalMessage->id,
            ]);

            ConversationMessage::createOutgoing([
                'conversation_id' => $this->conversation->conversation_id,
                'channel' => ChannelEnum::WEBSOCKET,
                'sender' => 'Agent2',
                'message' => 'Reply 2',
                'reply_to' => $originalMessage->id,
            ]);

            $replies = $originalMessage->replies;

            expect($replies)->toHaveCount(2)
                ->and($replies->pluck('message')->toArray())->toContain('Reply 1', 'Reply 2');
        });

        it('returns empty collection when no replies exist', function () {
            $message = ConversationMessage::createIncoming([
                'conversation_id' => $this->conversation->conversation_id,
                'channel' => ChannelEnum::WEBSOCKET,
                'sender' => 'user',
                'message' => 'No replies yet',
            ]);

            $replies = $message->replies;

            expect($replies)->toHaveCount(0);
        });
    });
});

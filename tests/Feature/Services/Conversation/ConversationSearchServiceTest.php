<?php

use App\Enums\ChannelEnum;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Conversation\ConversationSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new ConversationSearchService;
});

describe('ConversationSearchService', function () {
    it('returns matching conversations by message content', function () {
        // Arrange - create conversations with messages
        $conv1 = Conversation::createNew([
            'channel' => ChannelEnum::CLI,
            'sender' => 'user',
            'sender_id' => 'user-1',
        ]);
        ConversationMessage::create([
            'conversation_id' => $conv1->conversation_id,
            'channel' => ChannelEnum::CLI,
            'direction' => 'incoming',
            'sender' => 'user',
            'message' => 'How do I deploy a Laravel application?',
        ]);

        $conv2 = Conversation::createNew([
            'channel' => ChannelEnum::CLI,
            'sender' => 'user',
            'sender_id' => 'user-1',
        ]);
        ConversationMessage::create([
            'conversation_id' => $conv2->conversation_id,
            'channel' => ChannelEnum::CLI,
            'direction' => 'incoming',
            'sender' => 'user',
            'message' => 'What is the weather today?',
        ]);

        // Act - search for "Laravel"
        $results = $this->service->search('Laravel')->get();

        // Assert
        expect($results)->toHaveCount(1);
        expect($results->first()->conversation_id)->toBe($conv1->conversation_id);
    });

    it('returns empty result for non-matching query', function () {
        $conv = Conversation::createNew([
            'channel' => ChannelEnum::CLI,
            'sender' => 'user',
            'sender_id' => 'user-1',
        ]);
        ConversationMessage::create([
            'conversation_id' => $conv->conversation_id,
            'channel' => ChannelEnum::CLI,
            'direction' => 'incoming',
            'sender' => 'user',
            'message' => 'Hello world',
        ]);

        $results = $this->service->search('nonexistent-unicorn-query')->get();

        expect($results)->toBeEmpty();
    });

    it('filters by team_id when provided', function () {
        $conv1 = Conversation::createNew([
            'channel' => ChannelEnum::CLI,
            'sender' => 'user',
            'sender_id' => 'user-1',
            'team_id' => 'team-a',
        ]);
        ConversationMessage::create([
            'conversation_id' => $conv1->conversation_id,
            'channel' => ChannelEnum::CLI,
            'direction' => 'incoming',
            'sender' => 'user',
            'message' => 'Shared keyword here',
        ]);

        $conv2 = Conversation::createNew([
            'channel' => ChannelEnum::CLI,
            'sender' => 'user',
            'sender_id' => 'user-1',
            'team_id' => 'team-b',
        ]);
        ConversationMessage::create([
            'conversation_id' => $conv2->conversation_id,
            'channel' => ChannelEnum::CLI,
            'direction' => 'incoming',
            'sender' => 'user',
            'message' => 'Shared keyword here too',
        ]);

        $results = $this->service->search('keyword', 20, 'team-a')->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->team_id)->toBe('team-a');
    });

    it('respects limit parameter', function () {
        for ($i = 0; $i < 5; $i++) {
            $conv = Conversation::createNew([
                'channel' => ChannelEnum::CLI,
                'sender' => 'user',
                'sender_id' => 'user-1',
            ]);
            ConversationMessage::create([
                'conversation_id' => $conv->conversation_id,
                'channel' => ChannelEnum::CLI,
                'direction' => 'incoming',
                'sender' => 'user',
                'message' => "Searchable message number {$i}",
            ]);
        }

        $results = $this->service->search('Searchable', 3)->get();

        expect($results->count())->toBeLessThanOrEqual(3);
    });
});

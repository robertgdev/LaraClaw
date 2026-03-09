<?php

use App\Enums\ChannelEnum;
use App\Models\Conversation;
use App\Services\Conversation\ConversationSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = new ConversationSessionManager;
});

describe('ConversationSessionManager', function () {
    describe('startNewSession', function () {
        it('creates a new active session', function () {
            $session = $this->manager->startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            expect($session)->toBeInstanceOf(Conversation::class)
                ->and($session->is_active)->toBeTrue()
                ->and($session->sender_id)->toBe('user-1');
        });

        it('deactivates other sessions for same sender+channel', function () {
            $first = $this->manager->startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            $second = $this->manager->startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            $first->refresh();
            expect($first->is_active)->toBeFalse()
                ->and($second->is_active)->toBeTrue();
        });

        it('does not affect sessions from different channels', function () {
            $telegram = $this->manager->startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            $discord = $this->manager->startNewSession([
                'channel' => ChannelEnum::DISCORD,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            $telegram->refresh();
            expect($telegram->is_active)->toBeTrue()
                ->and($discord->is_active)->toBeTrue();
        });
    });

    describe('getActiveSession', function () {
        it('returns active session for sender', function () {
            $created = $this->manager->startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            $found = $this->manager->getActiveSession('user-1', ChannelEnum::TELEGRAM);

            expect($found)->not->toBeNull()
                ->and($found->conversation_id)->toBe($created->conversation_id);
        });

        it('returns null when no active session', function () {
            $result = $this->manager->getActiveSession('nonexistent', ChannelEnum::TELEGRAM);

            expect($result)->toBeNull();
        });
    });

    describe('getOrCreateActiveSession', function () {
        it('returns existing active session', function () {
            $existing = $this->manager->startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            $result = $this->manager->getOrCreateActiveSession('user-1', ChannelEnum::TELEGRAM, 'John');

            expect($result->conversation_id)->toBe($existing->conversation_id);
        });

        it('creates new session when none exists', function () {
            $result = $this->manager->getOrCreateActiveSession('user-1', ChannelEnum::TELEGRAM, 'John');

            expect($result)->toBeInstanceOf(Conversation::class)
                ->and($result->is_active)->toBeTrue();
        });
    });

    describe('getSessionsForSender', function () {
        it('returns all sessions for a sender', function () {
            $this->manager->startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);
            $this->manager->startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            $sessions = $this->manager->getSessionsForSender('user-1', ChannelEnum::TELEGRAM);

            expect($sessions)->toHaveCount(2);
        });

        it('respects limit parameter', function () {
            for ($i = 0; $i < 10; $i++) {
                $this->manager->startNewSession([
                    'channel' => ChannelEnum::TELEGRAM,
                    'sender' => 'John',
                    'sender_id' => 'user-1',
                ]);
            }

            $sessions = $this->manager->getSessionsForSender('user-1', ChannelEnum::TELEGRAM, 5);

            expect($sessions)->toHaveCount(5);
        });
    });

    describe('activate', function () {
        it('activates a session and deactivates siblings', function () {
            $session1 = Conversation::create([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
                'is_active' => false,
            ]);
            $session2 = Conversation::create([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
                'is_active' => true,
            ]);

            $this->manager->activate($session1);

            $session1->refresh();
            $session2->refresh();
            expect($session1->is_active)->toBeTrue()
                ->and($session2->is_active)->toBeFalse();
        });
    });
});

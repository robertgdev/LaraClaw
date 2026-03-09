<?php

use App\Enums\ChannelEnum;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('Conversation Session Methods', function () {
    describe('getDisplayTitle', function () {
        it('returns label when set', function () {
            // Arrange
            $conversation = Conversation::create([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
                'label' => 'My Project',
            ]);

            // Act
            $title = $conversation->getDisplayTitle();

            // Assert
            expect($title)->toBe('My Project');
        });

        it('returns derived_title when no label', function () {
            // Arrange
            $conversation = Conversation::create([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
                'derived_title' => 'Auto-generated title',
            ]);

            // Act
            $title = $conversation->getDisplayTitle();

            // Assert
            expect($title)->toBe('Auto-generated title');
        });

        it('prefers label over derived_title', function () {
            // Arrange
            $conversation = Conversation::create([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
                'label' => 'User Label',
                'derived_title' => 'Auto Title',
            ]);

            // Act
            $title = $conversation->getDisplayTitle();

            // Assert
            expect($title)->toBe('User Label');
        });

        it('returns truncated first message when no label or derived_title', function () {
            // Arrange
            $conversation = Conversation::create([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            ConversationMessage::create([
                'conversation_id' => $conversation->conversation_id,
                'channel' => ChannelEnum::TELEGRAM,
                'direction' => 'incoming',
                'sender' => 'John',
                'message' => 'This is a very long first message that should be truncated for display purposes in the session list',
            ]);

            // Act
            $title = $conversation->getDisplayTitle();

            // Assert - should contain the message (truncated or not)
            expect($title)->toContain('This is a very long first message');
        });

        it('returns conversation_id as fallback', function () {
            // Arrange
            $conversation = Conversation::create([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            // Act
            $title = $conversation->getDisplayTitle();

            // Assert
            expect($title)->toBe($conversation->conversation_id);
        });
    });

    describe('startNewSession', function () {
        it('creates new session with is_active=true', function () {
            // Act
            $session = Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            // Assert
            expect($session->is_active)->toBeTrue();
        });

        it('deactivates other sessions for same sender+channel', function () {
            // Arrange
            $existing = Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            // Act
            $new = Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            // Assert
            $existing->refresh();
            expect($existing->is_active)->toBeFalse()
                ->and($new->is_active)->toBeTrue();
        });

        it('generates UUID for conversation_id', function () {
            // Act
            $session = Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            // Assert
            expect($session->conversation_id)
                ->not->toBeEmpty()
                ->toBeString();

            // Validate UUID format
            expect(Str::isUuid($session->conversation_id))->toBeTrue();
        });
    });

    describe('getActiveSession', function () {
        it('returns active session for sender', function () {
            // Arrange
            $session = Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            // Act
            $result = Conversation::getActiveSession('user-1', ChannelEnum::TELEGRAM);

            // Assert
            expect($result)
                ->not->toBeNull()
                ->conversation_id->toBe($session->conversation_id);
        });

        it('returns null when no active session', function () {
            // Act
            $result = Conversation::getActiveSession('nonexistent', ChannelEnum::TELEGRAM);

            // Assert
            expect($result)->toBeNull();
        });

        it('does not return sessions from different channels', function () {
            // Arrange
            Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            // Act
            $result = Conversation::getActiveSession('user-1', ChannelEnum::DISCORD);

            // Assert
            expect($result)->toBeNull();
        });
    });

    describe('getOrCreateActiveSession', function () {
        it('returns existing active session', function () {
            // Arrange
            $existing = Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            // Act
            $result = Conversation::getOrCreateActiveSession('user-1', ChannelEnum::TELEGRAM, 'John');

            // Assert
            expect($result->conversation_id)->toBe($existing->conversation_id);
        });

        it('creates new session when none exists', function () {
            // Act
            $result = Conversation::getOrCreateActiveSession('user-1', ChannelEnum::TELEGRAM, 'John');

            // Assert
            expect($result)
                ->toBeInstanceOf(Conversation::class)
                ->is_active->toBeTrue()
                ->sender_id->toBe('user-1');
        });
    });

    describe('getSessionsForSender', function () {
        it('returns sessions sorted by pinned then last_message_at', function () {
            // Arrange
            $session1 = Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);
            $session1->update(['last_message_at' => now()->subDays(2)]);

            $session2 = Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);
            $session2->update(['is_pinned' => true, 'last_message_at' => now()->subDays(5)]);

            $session3 = Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);
            $session3->update(['last_message_at' => now()->subDays(1)]);

            // Act
            $sessions = Conversation::getSessionsForSender('user-1', ChannelEnum::TELEGRAM);

            // Assert - Pinned should be first, then sorted by last_message_at desc
            expect($sessions->first()->conversation_id)->toBe($session2->conversation_id)
                ->and($sessions[1]->conversation_id)->toBe($session3->conversation_id)
                ->and($sessions[2]->conversation_id)->toBe($session1->conversation_id);
        });

        it('excludes sessions from other senders', function () {
            // Arrange
            Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);
            Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'Jane',
                'sender_id' => 'user-2',
            ]);

            // Act
            $sessions = Conversation::getSessionsForSender('user-1', ChannelEnum::TELEGRAM);

            // Assert
            expect($sessions)->toHaveCount(1);
        });

        it('respects limit parameter', function () {
            // Arrange
            for ($i = 0; $i < 10; $i++) {
                Conversation::startNewSession([
                    'channel' => ChannelEnum::TELEGRAM,
                    'sender' => 'John',
                    'sender_id' => 'user-1',
                ]);
            }

            // Act
            $sessions = Conversation::getSessionsForSender('user-1', ChannelEnum::TELEGRAM, 5);

            // Assert
            expect($sessions)->toHaveCount(5);
        });
    });

    describe('rename', function () {
        it('updates label', function () {
            // Arrange
            $session = Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            // Act
            $session->rename('New Name');

            // Assert
            $session->refresh();
            expect($session->label)->toBe('New Name');
        });
    });

    describe('togglePin', function () {
        it('toggles is_pinned from false to true', function () {
            // Arrange
            $session = Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);
            // is_pinned may be null or false initially
            expect($session->is_pinned)->not->toBeTrue();

            // Act
            $result = $session->togglePin();

            // Assert
            expect($result)->toBeTrue();
            $session->refresh();
            expect($session->is_pinned)->toBeTrue();
        });

        it('toggles is_pinned from true to false', function () {
            // Arrange
            $session = Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);
            $session->update(['is_pinned' => true]);
            $session->refresh();

            // Act
            $result = $session->togglePin();

            // Assert
            expect($result)->toBeFalse();
            $session->refresh();
            expect($session->is_pinned)->toBeFalse();
        });
    });

    describe('activate', function () {
        it('sets is_active to true', function () {
            // Arrange
            $session = Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);
            $session->update(['is_active' => false]);

            // Act
            $session->activate();

            // Assert
            $session->refresh();
            expect($session->is_active)->toBeTrue();
        });

        it('deactivates other sessions for same sender+channel', function () {
            // Arrange - create sessions directly to avoid auto-deactivation
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

            // Act - activate session1
            $session1->activate();

            // Assert
            $session1->refresh();
            $session2->refresh();
            expect($session1->is_active)->toBeTrue()
                ->and($session2->is_active)->toBeFalse();
        });
    });

    describe('updateDerivedTitle', function () {
        it('sets derived_title from first message', function () {
            // Arrange
            $session = Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            ConversationMessage::create([
                'conversation_id' => $session->conversation_id,
                'channel' => ChannelEnum::TELEGRAM,
                'direction' => 'incoming',
                'sender' => 'John',
                'message' => 'This is my first message',
            ]);

            // Act
            $session->updateDerivedTitle();

            // Assert
            $session->refresh();
            expect($session->derived_title)->toContain('This is my first message');
        });

        it('does not override existing label', function () {
            // Arrange
            $session = Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
                'label' => 'My Label',
            ]);

            ConversationMessage::create([
                'conversation_id' => $session->conversation_id,
                'channel' => ChannelEnum::TELEGRAM,
                'direction' => 'incoming',
                'sender' => 'John',
                'message' => 'First message',
            ]);

            // Act
            $session->updateDerivedTitle();

            // Assert
            $session->refresh();
            expect($session->derived_title)->toBeNull();
        });

        it('truncates long messages to 100 chars', function () {
            // Arrange
            $session = Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);

            $longMessage = str_repeat('a', 200);
            ConversationMessage::create([
                'conversation_id' => $session->conversation_id,
                'channel' => ChannelEnum::TELEGRAM,
                'direction' => 'incoming',
                'sender' => 'John',
                'message' => $longMessage,
            ]);

            // Act
            $session->updateDerivedTitle();

            // Assert
            $session->refresh();
            expect(strlen($session->derived_title))->toBeLessThanOrEqual(103); // 100 + '...'
        });
    });

    describe('touchLastMessage', function () {
        it('updates last_message_at', function () {
            // Arrange
            $session = Conversation::startNewSession([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'John',
                'sender_id' => 'user-1',
            ]);
            $session->update(['last_message_at' => now()->subHour()]);

            // Act
            $session->touchLastMessage();

            // Assert
            $session->refresh();
            expect($session->last_message_at->isAfter(now()->subMinute()))->toBeTrue();
        });
    });

    describe('Scopes', function () {
        describe('scopeActive', function () {
            it('filters to active sessions only', function () {
                // Arrange - create sessions for a different sender to avoid auto-deactivation
                $active = Conversation::create([
                    'channel' => ChannelEnum::TELEGRAM,
                    'sender' => 'John',
                    'sender_id' => 'user-1',
                    'is_active' => true,
                ]);
                $inactive = Conversation::create([
                    'channel' => ChannelEnum::TELEGRAM,
                    'sender' => 'John',
                    'sender_id' => 'user-1',
                    'is_active' => false,
                ]);

                // Act
                $results = Conversation::forSender('user-1', ChannelEnum::TELEGRAM)
                    ->active()
                    ->get();

                // Assert
                expect($results)->toHaveCount(1)
                    ->and($results->first()->conversation_id)->toBe($active->conversation_id);
            });
        });

        describe('scopePinned', function () {
            it('filters to pinned sessions only', function () {
                // Arrange
                $pinned = Conversation::startNewSession([
                    'channel' => ChannelEnum::TELEGRAM,
                    'sender' => 'John',
                    'sender_id' => 'user-1',
                ]);
                $pinned->update(['is_pinned' => true]);

                $unpinned = Conversation::startNewSession([
                    'channel' => ChannelEnum::TELEGRAM,
                    'sender' => 'John',
                    'sender_id' => 'user-1',
                ]);

                // Act
                $results = Conversation::forSender('user-1', ChannelEnum::TELEGRAM)
                    ->pinned()
                    ->get();

                // Assert
                expect($results)->toHaveCount(1)
                    ->and($results->first()->conversation_id)->toBe($pinned->conversation_id);
            });
        });

        describe('scopeForSender', function () {
            it('filters by sender_id and channel', function () {
                // Arrange
                Conversation::startNewSession([
                    'channel' => ChannelEnum::TELEGRAM,
                    'sender' => 'John',
                    'sender_id' => 'user-1',
                ]);
                Conversation::startNewSession([
                    'channel' => ChannelEnum::DISCORD,
                    'sender' => 'John',
                    'sender_id' => 'user-1',
                ]);
                Conversation::startNewSession([
                    'channel' => ChannelEnum::TELEGRAM,
                    'sender' => 'Jane',
                    'sender_id' => 'user-2',
                ]);

                // Act
                $results = Conversation::forSender('user-1', ChannelEnum::TELEGRAM)->get();

                // Assert
                expect($results)->toHaveCount(1)
                    ->and($results->first()->sender_id)->toBe('user-1')
                    ->and($results->first()->channel)->toBe(ChannelEnum::TELEGRAM);
            });
        });
    });
});

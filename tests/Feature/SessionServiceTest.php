<?php

use App\Enums\ChannelEnum;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\SessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(SessionService::class);
});

describe('SessionService', function () {
    describe('createSession', function () {
        it('creates a new session with correct attributes', function () {
            // Act
            $session = $this->service->createSession(
                ChannelEnum::TELEGRAM,
                'user-123',
                'John Doe'
            );

            // Assert
            expect($session)
                ->toBeInstanceOf(Conversation::class)
                ->sender_id->toBe('user-123')
                ->sender->toBe('John Doe')
                ->channel->toBe(ChannelEnum::TELEGRAM)
                ->is_active->toBeTrue()
                ->conversation_id->not->toBeEmpty();
        });

        it('creates session with custom label', function () {
            // Act
            $session = $this->service->createSession(
                ChannelEnum::TELEGRAM,
                'user-123',
                'John Doe',
                'My Project'
            );

            // Assert
            expect($session->label)->toBe('My Project');
        });

        it('deactivates other sessions for same sender', function () {
            // Arrange - create an existing session
            $existing = $this->service->createSession(
                ChannelEnum::TELEGRAM,
                'user-123',
                'John Doe'
            );

            // Act - create new session
            $newSession = $this->service->createSession(
                ChannelEnum::TELEGRAM,
                'user-123',
                'John Doe'
            );

            // Assert
            $existing->refresh();
            expect($existing->is_active)->toBeFalse()
                ->and($newSession->is_active)->toBeTrue();
        });

        it('does not affect sessions from different senders', function () {
            // Arrange
            $user1Session = $this->service->createSession(
                ChannelEnum::TELEGRAM,
                'user-1',
                'User One'
            );

            // Act
            $user2Session = $this->service->createSession(
                ChannelEnum::TELEGRAM,
                'user-2',
                'User Two'
            );

            // Assert
            $user1Session->refresh();
            expect($user1Session->is_active)->toBeTrue()
                ->and($user2Session->is_active)->toBeTrue();
        });

        it('does not affect sessions from different channels', function () {
            // Arrange
            $telegramSession = $this->service->createSession(
                ChannelEnum::TELEGRAM,
                'user-123',
                'John'
            );

            // Act
            $discordSession = $this->service->createSession(
                ChannelEnum::DISCORD,
                'user-123',
                'John'
            );

            // Assert
            $telegramSession->refresh();
            expect($telegramSession->is_active)->toBeTrue()
                ->and($discordSession->is_active)->toBeTrue();
        });
    });

    describe('getOrCreateActiveSession', function () {
        it('returns existing active session', function () {
            // Arrange
            $existing = $this->service->createSession(
                ChannelEnum::TELEGRAM,
                'user-123',
                'John'
            );

            // Act
            $session = $this->service->getOrCreateActiveSession(
                ChannelEnum::TELEGRAM,
                'user-123',
                'John'
            );

            // Assert
            expect($session->conversation_id)->toBe($existing->conversation_id);
        });

        it('creates new session when none exists', function () {
            // Act
            $session = $this->service->getOrCreateActiveSession(
                ChannelEnum::TELEGRAM,
                'user-123',
                'John'
            );

            // Assert
            expect($session)
                ->toBeInstanceOf(Conversation::class)
                ->is_active->toBeTrue()
                ->sender_id->toBe('user-123');
        });
    });

    describe('getSessions', function () {
        it('returns all sessions for a sender', function () {
            // Arrange
            $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'User');
            $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'User');
            $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'User');

            // Act
            $sessions = $this->service->getSessions(ChannelEnum::TELEGRAM, 'user-1');

            // Assert
            expect($sessions)->toHaveCount(3);
        });

        it('does not return sessions from other senders', function () {
            // Arrange
            $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'User');
            $this->service->createSession(ChannelEnum::TELEGRAM, 'user-2', 'User');

            // Act
            $sessions = $this->service->getSessions(ChannelEnum::TELEGRAM, 'user-1');

            // Assert
            expect($sessions)->toHaveCount(1);
        });

        it('respects limit parameter', function () {
            // Arrange
            for ($i = 0; $i < 10; $i++) {
                $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'User');
            }

            // Act
            $sessions = $this->service->getSessions(ChannelEnum::TELEGRAM, 'user-1', 5);

            // Assert
            expect($sessions)->toHaveCount(5);
        });

        it('returns empty collection when no sessions exist', function () {
            // Act
            $sessions = $this->service->getSessions(ChannelEnum::TELEGRAM, 'nonexistent');

            // Assert
            expect($sessions)
                ->toBeInstanceOf(Collection::class)
                ->toBeEmpty();
        });
    });

    describe('switchToSession', function () {
        it('switches to specified session', function () {
            // Arrange
            $session1 = $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'User');
            $session2 = $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'User');

            // Act
            $result = $this->service->switchToSession(
                $session1->conversation_id,
                ChannelEnum::TELEGRAM,
                'user-1'
            );

            // Assert
            expect($result)->not->toBeNull();
            $session1->refresh();
            $session2->refresh();
            expect($session1->is_active)->toBeTrue()
                ->and($session2->is_active)->toBeFalse();
        });

        it('returns null for non-existent session', function () {
            // Act
            $result = $this->service->switchToSession(
                'non-existent-id',
                ChannelEnum::TELEGRAM,
                'user-1'
            );

            // Assert
            expect($result)->toBeNull();
        });

        it('returns null when session belongs to different sender', function () {
            // Arrange
            $session = $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'User');

            // Act
            $result = $this->service->switchToSession(
                $session->conversation_id,
                ChannelEnum::TELEGRAM,
                'user-2'
            );

            // Assert
            expect($result)->toBeNull();
        });
    });

    describe('renameSession', function () {
        it('renames session successfully', function () {
            // Arrange
            $session = $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'User');

            // Act
            $result = $this->service->renameSession(
                $session->conversation_id,
                'New Name',
                ChannelEnum::TELEGRAM,
                'user-1'
            );

            // Assert
            expect($result)->toBeTrue();
            $session->refresh();
            expect($session->label)->toBe('New Name');
        });

        it('returns false for non-existent session', function () {
            // Act
            $result = $this->service->renameSession(
                'non-existent',
                'New Name',
                ChannelEnum::TELEGRAM,
                'user-1'
            );

            // Assert
            expect($result)->toBeFalse();
        });

        it('returns false when session belongs to different sender', function () {
            // Arrange
            $session = $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'User');

            // Act
            $result = $this->service->renameSession(
                $session->conversation_id,
                'New Name',
                ChannelEnum::TELEGRAM,
                'user-2'
            );

            // Assert
            expect($result)->toBeFalse();
        });
    });

    describe('togglePin', function () {
        it('pins an unpinned session', function () {
            // Arrange
            $session = $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'User');
            // is_pinned may be null or false initially
            expect($session->is_pinned)->not->toBeTrue();

            // Act
            $result = $this->service->togglePin(
                $session->conversation_id,
                ChannelEnum::TELEGRAM,
                'user-1'
            );

            // Assert
            expect($result)->toBeTrue();
            $session->refresh();
            expect($session->is_pinned)->toBeTrue();
        });

        it('unpins a pinned session', function () {
            // Arrange
            $session = $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'User');
            $session->update(['is_pinned' => true]);

            // Act
            $result = $this->service->togglePin(
                $session->conversation_id,
                ChannelEnum::TELEGRAM,
                'user-1'
            );

            // Assert
            expect($result)->toBeFalse();
            $session->refresh();
            expect($session->is_pinned)->toBeFalse();
        });

        it('returns null for non-existent session', function () {
            // Act
            $result = $this->service->togglePin(
                'non-existent',
                ChannelEnum::TELEGRAM,
                'user-1'
            );

            // Assert
            expect($result)->toBeNull();
        });
    });

    describe('deleteSession', function () {
        it('soft deletes session', function () {
            // Arrange
            $session = $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'User');

            // Act
            $result = $this->service->deleteSession(
                $session->conversation_id,
                ChannelEnum::TELEGRAM,
                'user-1'
            );

            // Assert
            expect($result)->toBeTrue();
            expect(Conversation::find($session->id))->toBeNull();
            expect(Conversation::withTrashed()->find($session->id))->not->toBeNull();
        });

        it('returns false for non-existent session', function () {
            // Act
            $result = $this->service->deleteSession(
                'non-existent',
                ChannelEnum::TELEGRAM,
                'user-1'
            );

            // Assert
            expect($result)->toBeFalse();
        });

        it('returns false when session belongs to different sender', function () {
            // Arrange
            $session = $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'User');

            // Act
            $result = $this->service->deleteSession(
                $session->conversation_id,
                ChannelEnum::TELEGRAM,
                'user-2'
            );

            // Assert
            expect($result)->toBeFalse();
        });
    });

    describe('formatSessionList', function () {
        it('returns message for empty sessions', function () {
            // Act
            $result = $this->service->formatSessionList(collect());

            // Assert
            expect($result)->toContain('No sessions found');
        });

        it('formats sessions correctly', function () {
            // Arrange
            $session1 = $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'User');
            $session1->update(['label' => 'Project Alpha']);

            $session2 = $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'User');
            $session2->update(['label' => 'Project Beta', 'is_pinned' => true]);

            $sessions = collect([$session2, $session1]); // Pinned first

            // Act
            $result = $this->service->formatSessionList($sessions);

            // Assert
            expect($result)
                ->toContain('Your sessions:')
                ->toContain('Project Alpha')
                ->toContain('Project Beta')
                ->toContain('📌');
        });

        it('shows active session marker', function () {
            // Arrange
            $session = $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'User');
            $session->update(['label' => 'Active Session', 'is_active' => true]);

            // Act
            $result = $this->service->formatSessionList(collect([$session]));

            // Assert
            expect($result)->toContain('(active)');
        });
    });

    describe('detectSessionIntent', function () {
        it('detects new session intent', function () {
            expect($this->service->detectSessionIntent('new session'))->toBe('new_session')
                ->and($this->service->detectSessionIntent('start session'))->toBe('new_session')
                ->and($this->service->detectSessionIntent('new conversation'))->toBe('new_session')
                ->and($this->service->detectSessionIntent('start chat'))->toBe('new_session');
        });

        it('detects show sessions intent', function () {
            expect($this->service->detectSessionIntent('show sessions'))->toBe('show_sessions')
                ->and($this->service->detectSessionIntent('list sessions'))->toBe('show_sessions')
                ->and($this->service->detectSessionIntent('show my sessions'))->toBe('show_sessions')
                ->and($this->service->detectSessionIntent('list conversations'))->toBe('show_sessions');
        });

        it('detects switch session intent by number', function () {
            expect($this->service->detectSessionIntent('1'))->toBe('switch_session')
                ->and($this->service->detectSessionIntent('2'))->toBe('switch_session')
                ->and($this->service->detectSessionIntent('10'))->toBe('switch_session');
        });

        it('detects rename session intent', function () {
            expect($this->service->detectSessionIntent('rename session to Project X'))->toBe('rename_session')
                ->and($this->service->detectSessionIntent('rename session Project to New Name'))->toBe('rename_session');
        });

        it('detects pin session intent', function () {
            expect($this->service->detectSessionIntent('pin session 1'))->toBe('pin_session')
                ->and($this->service->detectSessionIntent('pin 2'))->toBe('pin_session')
                ->and($this->service->detectSessionIntent('unpin session 3'))->toBe('pin_session')
                ->and($this->service->detectSessionIntent('unpin 1'))->toBe('pin_session');
        });

        it('detects delete session intent', function () {
            expect($this->service->detectSessionIntent('delete session 1'))->toBe('delete_session')
                ->and($this->service->detectSessionIntent('delete 2'))->toBe('delete_session');
        });

        it('returns null for non-session messages', function () {
            expect($this->service->detectSessionIntent('hello world'))->toBeNull()
                ->and($this->service->detectSessionIntent('what is the weather?'))->toBeNull()
                ->and($this->service->detectSessionIntent('write some code'))->toBeNull();
        });

        it('is case insensitive', function () {
            expect($this->service->detectSessionIntent('NEW SESSION'))->toBe('new_session')
                ->and($this->service->detectSessionIntent('Show Sessions'))->toBe('show_sessions')
                ->and($this->service->detectSessionIntent('PIN SESSION 1'))->toBe('pin_session');
        });
    });

    describe('handleSessionIntent', function () {
        it('handles new_session intent', function () {
            // Act
            $result = $this->service->handleSessionIntent(
                'new_session',
                'new session',
                ChannelEnum::TELEGRAM,
                'user-1',
                'John'
            );

            // Assert
            expect($result->handled)->toBeTrue()
                ->and($result->response)->toContain('Started new session')
                ->and($result->session)->toBeInstanceOf(Conversation::class);
        });

        it('handles show_sessions intent', function () {
            // Arrange
            $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'John');

            // Act
            $result = $this->service->handleSessionIntent(
                'show_sessions',
                'show sessions',
                ChannelEnum::TELEGRAM,
                'user-1',
                'John'
            );

            // Assert
            expect($result->handled)->toBeTrue()
                ->and($result->response)->toContain('Your sessions:');
        });

        it('handles switch_session intent', function () {
            // Arrange
            $session1 = $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'John');
            $session2 = $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'John');

            // Act - switch to session 1 (index 0)
            $result = $this->service->handleSessionIntent(
                'switch_session',
                '1',
                ChannelEnum::TELEGRAM,
                'user-1',
                'John'
            );

            // Assert
            expect($result->handled)->toBeTrue()
                ->and($result->response)->toContain('Switched to:')
                ->and($result->session)->not->toBeNull();
        });

        it('handles invalid switch_session number', function () {
            // Act
            $result = $this->service->handleSessionIntent(
                'switch_session',
                '999',
                ChannelEnum::TELEGRAM,
                'user-1',
                'John'
            );

            // Assert
            expect($result->handled)->toBeTrue()
                ->and($result->response)->toContain('Invalid session number');
        });

        it('handles rename_session intent', function () {
            // Arrange
            $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'John');

            // Act
            $result = $this->service->handleSessionIntent(
                'rename_session',
                'rename session to My Project',
                ChannelEnum::TELEGRAM,
                'user-1',
                'John'
            );

            // Assert
            expect($result->handled)->toBeTrue()
                ->and($result->response)->toContain('Session renamed to: My Project');
        });

        it('handles pin_session intent', function () {
            // Arrange
            $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'John');

            // Act
            $result = $this->service->handleSessionIntent(
                'pin_session',
                'pin session 1',
                ChannelEnum::TELEGRAM,
                'user-1',
                'John'
            );

            // Assert
            expect($result->handled)->toBeTrue()
                ->and($result->response)->toContain('Session pinned:');
        });

        it('handles unpin_session intent', function () {
            // Arrange
            $session = $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'John');
            $session->update(['is_pinned' => true]);

            // Act
            $result = $this->service->handleSessionIntent(
                'pin_session',
                'unpin session 1',
                ChannelEnum::TELEGRAM,
                'user-1',
                'John'
            );

            // Assert
            expect($result->handled)->toBeTrue()
                ->and($result->response)->toContain('Session unpinned:');
        });

        it('handles delete_session intent', function () {
            // Arrange
            $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'John');

            // Act
            $result = $this->service->handleSessionIntent(
                'delete_session',
                'delete session 1',
                ChannelEnum::TELEGRAM,
                'user-1',
                'John'
            );

            // Assert
            expect($result->handled)->toBeTrue()
                ->and($result->response)->toContain('Session deleted:');
        });

        it('returns handled=false for unknown intent', function () {
            // Act
            $result = $this->service->handleSessionIntent(
                'unknown_intent',
                'test',
                ChannelEnum::TELEGRAM,
                'user-1',
                'John'
            );

            // Assert
            expect($result->handled)->toBeFalse()
                ->and($result->response)->toBeNull()
                ->and($result->session)->toBeNull();
        });
    });

    describe('getSessionHistory', function () {
        it('returns formatted history', function () {
            // Arrange
            $session = $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'John');

            ConversationMessage::create([
                'conversation_id' => $session->conversation_id,
                'channel' => ChannelEnum::TELEGRAM,
                'direction' => 'incoming',
                'sender' => 'John',
                'message' => 'Hello',
            ]);

            ConversationMessage::create([
                'conversation_id' => $session->conversation_id,
                'channel' => ChannelEnum::TELEGRAM,
                'direction' => 'outgoing',
                'sender' => 'Assistant',
                'message' => 'Hi there!',
            ]);

            // Act
            $history = $this->service->getSessionHistory($session->conversation_id);

            // Assert
            expect($history)->toHaveCount(2)
                ->and($history[0]->role)->toBe('user')
                ->and($history[0]->content)->toBe('Hello')
                ->and($history[1]->role)->toBe('assistant')
                ->and($history[1]->content)->toBe('Hi there!');
        });

        it('respects limit parameter', function () {
            // Arrange
            $session = $this->service->createSession(ChannelEnum::TELEGRAM, 'user-1', 'John');

            for ($i = 0; $i < 10; $i++) {
                ConversationMessage::create([
                    'conversation_id' => $session->conversation_id,
                    'channel' => ChannelEnum::TELEGRAM,
                    'direction' => 'incoming',
                    'sender' => 'John',
                    'message' => "Message {$i}",
                ]);
            }

            // Act
            $history = $this->service->getSessionHistory($session->conversation_id, 5);

            // Assert
            expect($history)->toHaveCount(5);
        });

        it('returns empty array for non-existent session', function () {
            // Act
            $history = $this->service->getSessionHistory('non-existent');

            // Assert
            expect($history)->toBeEmpty();
        });
    });
});

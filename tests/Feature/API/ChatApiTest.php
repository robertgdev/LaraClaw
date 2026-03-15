<?php

use App\Enums\ChannelEnum;
use App\Enums\FeedbackEnum;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ────────────────────────────────────────────────────
// Helpers
// ────────────────────────────────────────────────────

/** Return headers that include a valid API key. */
function apiHeaders(): array
{
    return ['Authorization' => 'Bearer test-api-key-for-testing'];
}

/** Create a test conversation with an optional outgoing (assistant) message. */
function makeConversation(?string $convId = null, bool $withMessage = false): Conversation
{
    $convId ??= 'test-conv-'.uniqid();

    $conv = Conversation::create([
        'conversation_id' => $convId,
        'channel' => ChannelEnum::WEBSOCKET,
        'sender' => 'user',
        'sender_id' => 'ws_test',
        'is_active' => true,
        'derived_title' => 'Test Chat',
    ]);

    if ($withMessage) {
        ConversationMessage::createIncoming([
            'conversation_id' => $conv->conversation_id,
            'channel' => ChannelEnum::WEBSOCKET,
            'sender' => 'user',
            'sender_id' => 'ws_test',
            'message' => 'Hello agent',
        ]);
        ConversationMessage::createOutgoing([
            'conversation_id' => $conv->conversation_id,
            'channel' => ChannelEnum::WEBSOCKET,
            'sender' => 'Agent',
            'message' => 'Hello user',
        ]);
    }

    return $conv;
}

// ────────────────────────────────────────────────────
// Auth middleware
// ────────────────────────────────────────────────────

describe('REST API auth middleware', function () {
    it('rejects requests with no API key', function () {
        $response = $this->getJson('/api/sessions');
        $response->assertStatus(401);
    });

    it('rejects requests with a wrong API key', function () {
        $response = $this->getJson('/api/sessions', ['Authorization' => 'Bearer wrong-key']);
        $response->assertStatus(401);
    });

    it('accepts requests with the server API key', function () {
        $response = $this->getJson('/api/sessions', apiHeaders());
        $response->assertStatus(200);
    });

    it('accepts requests with X-API-Key header', function () {
        $response = $this->getJson('/api/sessions', ['X-API-Key' => 'test-api-key-for-testing']);
        $response->assertStatus(200);
    });

    it('accepts requests with api_key query param', function () {
        $response = $this->getJson('/api/sessions?api_key=test-api-key-for-testing');
        $response->assertStatus(200);
    });
});

// ────────────────────────────────────────────────────
// GET /api/ping
// ────────────────────────────────────────────────────

describe('GET /api/ping', function () {
    it('returns ok: true', function () {
        $this->getJson('/api/ping', apiHeaders())
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    });
});

// ────────────────────────────────────────────────────
// GET /api/sessions
// ────────────────────────────────────────────────────

describe('GET /api/sessions', function () {
    it('returns an empty sessions list when no conversations exist', function () {
        $response = $this->getJson('/api/sessions', apiHeaders());
        $response->assertStatus(200)
            ->assertJsonStructure(['sessions'])
            ->assertJson(['sessions' => []]);
    });

    it('returns all conversations in the sessions list', function () {
        makeConversation('conv-a');
        makeConversation('conv-b');

        $response = $this->getJson('/api/sessions', apiHeaders());
        $response->assertStatus(200);

        $sessions = $response->json('sessions');
        expect(count($sessions))->toBe(2);
    });

    it('returns required fields for each session', function () {
        makeConversation('conv-fields', true);

        $response = $this->getJson('/api/sessions', apiHeaders());
        $response->assertStatus(200)
            ->assertJsonStructure([
                'sessions' => [
                    '*' => ['key', 'friendlyId', 'updatedAt'],
                ],
            ]);
    });

    it('returns the feedback value for a session', function () {
        $conv = makeConversation('conv-fb');
        $conv->setFeedback(FeedbackEnum::POSITIVE);

        $response = $this->getJson('/api/sessions', apiHeaders());
        $sessions = $response->json('sessions');

        $found = collect($sessions)->firstWhere('key', 'conv-fb');
        expect($found)->not->toBeNull();
        expect($found['feedback'])->toBe(1);
    });

    it('returns null feedback when no feedback set', function () {
        makeConversation('conv-no-fb');

        $response = $this->getJson('/api/sessions', apiHeaders());
        $sessions = $response->json('sessions');

        $found = collect($sessions)->firstWhere('key', 'conv-no-fb');
        expect($found['feedback'])->toBeNull();
    });
});

// ────────────────────────────────────────────────────
// POST /api/sessions
// ────────────────────────────────────────────────────

describe('POST /api/sessions', function () {
    it('creates a new session and returns sessionKey and friendlyId', function () {
        $response = $this->postJson('/api/sessions', [], apiHeaders());
        $response->assertStatus(200)
            ->assertJsonStructure(['sessionKey', 'friendlyId']);

        $sessionKey = $response->json('sessionKey');
        expect($sessionKey)->toBeString()->not->toBeEmpty();

        // Verify conversation was created in the database
        $this->assertDatabaseHas('conversations', [
            'conversation_id' => $sessionKey,
        ]);
    });
});

// ────────────────────────────────────────────────────
// DELETE /api/sessions
// ────────────────────────────────────────────────────

describe('DELETE /api/sessions', function () {
    it('deletes an existing session by sessionKey', function () {
        $conv = makeConversation('delete-me');

        $this->deleteJson('/api/sessions?sessionKey=delete-me', [], apiHeaders())
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSoftDeleted('conversations', [
            'conversation_id' => 'delete-me',
        ]);
    });

    it('deletes an existing session by friendlyId', function () {
        $conv = makeConversation('delete-by-friendly');

        $this->deleteJson('/api/sessions?friendlyId=delete-by-friendly', [], apiHeaders())
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSoftDeleted('conversations', [
            'conversation_id' => 'delete-by-friendly',
        ]);
    });

    it('returns success even when the session does not exist', function () {
        $this->deleteJson('/api/sessions?sessionKey=does-not-exist', [], apiHeaders())
            ->assertStatus(200)
            ->assertJson(['success' => true]);
    });
});

// ────────────────────────────────────────────────────
// POST /api/sessions/rename
// ────────────────────────────────────────────────────

describe('POST /api/sessions/rename', function () {
    it('renames a session', function () {
        makeConversation('rename-me');

        $this->postJson('/api/sessions/rename', [
            'sessionKey' => 'rename-me',
            'title' => 'My renamed chat',
        ], apiHeaders())
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('conversations', [
            'conversation_id' => 'rename-me',
            'label' => 'My renamed chat',
        ]);
    });

    it('returns success even when session does not exist', function () {
        $this->postJson('/api/sessions/rename', [
            'sessionKey' => 'ghost-session',
            'title' => 'Ghost',
        ], apiHeaders())
            ->assertStatus(200)
            ->assertJson(['success' => true]);
    });

    it('returns 422 when sessionKey is missing', function () {
        $this->postJson('/api/sessions/rename', ['title' => 'No key'], apiHeaders())
            ->assertStatus(422);
    });

    it('returns 422 when title is missing', function () {
        $this->postJson('/api/sessions/rename', ['sessionKey' => 'some-key'], apiHeaders())
            ->assertStatus(422);
    });
});

// ────────────────────────────────────────────────────
// GET /api/history
// ────────────────────────────────────────────────────

describe('GET /api/history', function () {
    it('returns an empty messages array for a new session', function () {
        makeConversation('history-empty');

        $this->getJson('/api/history?sessionKey=history-empty', apiHeaders())
            ->assertStatus(200)
            ->assertJson(['messages' => []]);
    });

    it('returns 200 with empty messages when session does not exist', function () {
        $this->getJson('/api/history?sessionKey=no-such-session', apiHeaders())
            ->assertStatus(200)
            ->assertJson(['messages' => []]);
    });

    it('returns messages with required fields', function () {
        makeConversation('history-msgs', true);

        $response = $this->getJson('/api/history?sessionKey=history-msgs', apiHeaders());
        $response->assertStatus(200)
            ->assertJsonStructure([
                'sessionKey',
                'messages' => [
                    '*' => ['id', 'messageId', 'role', 'content', 'timestamp'],
                ],
            ]);
    });

    it('returns messages with feedback field', function () {
        $conv = makeConversation('history-fb', true);

        // Give feedback to the outgoing message
        $msg = ConversationMessage::where('conversation_id', 'history-fb')
            ->where('direction', 'outgoing')
            ->first();
        $msg->setFeedback(FeedbackEnum::NEGATIVE);

        $response = $this->getJson('/api/history?sessionKey=history-fb', apiHeaders());
        $messages = $response->json('messages');

        $assistantMsg = collect($messages)->firstWhere('role', 'assistant');
        expect($assistantMsg)->not->toBeNull();
        expect($assistantMsg['feedback'])->toBe(-1);
    });

    it('sets role=user for incoming messages and role=assistant for outgoing', function () {
        makeConversation('history-roles', true);

        $response = $this->getJson('/api/history?sessionKey=history-roles', apiHeaders());
        $messages = $response->json('messages');

        $roles = collect($messages)->pluck('role')->toArray();
        expect($roles)->toContain('user');
        expect($roles)->toContain('assistant');
    });

    it('returns messages using friendlyId', function () {
        makeConversation('history-friendly', true);

        $this->getJson('/api/history?friendlyId=history-friendly', apiHeaders())
            ->assertStatus(200)
            ->assertJsonPath('sessionKey', 'history-friendly');
    });

    it('messages include messageId (UUID) field', function () {
        makeConversation('history-uuid', true);

        $response = $this->getJson('/api/history?sessionKey=history-uuid', apiHeaders());
        $messages = $response->json('messages');

        expect($messages)->not->toBeEmpty();
        foreach ($messages as $msg) {
            expect($msg)->toHaveKey('messageId');
            expect($msg['messageId'])->toBeString()->not->toBeEmpty();
        }
    });
});

// ────────────────────────────────────────────────────
// POST /api/feedback/message
// ────────────────────────────────────────────────────

describe('POST /api/feedback/message', function () {
    it('stores positive feedback for a message by UUID', function () {
        $conv = makeConversation('fb-msg-conv', true);
        $msg = ConversationMessage::where('conversation_id', 'fb-msg-conv')->first();

        $this->postJson('/api/feedback/message', [
            'messageId' => $msg->message_id,
            'feedback' => 1,
        ], apiHeaders())
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'feedback' => 1,
            ]);

        $this->assertDatabaseHas('conversation_messages', [
            'message_id' => $msg->message_id,
            'feedback' => FeedbackEnum::POSITIVE->value,
        ]);
    });

    it('stores neutral feedback for a message', function () {
        $conv = makeConversation('fb-msg-neutral', true);
        $msg = ConversationMessage::where('conversation_id', 'fb-msg-neutral')->first();

        $this->postJson('/api/feedback/message', [
            'messageId' => $msg->message_id,
            'feedback' => 0,
        ], apiHeaders())
            ->assertStatus(200)
            ->assertJson(['feedback' => 0]);
    });

    it('stores negative feedback for a message', function () {
        $conv = makeConversation('fb-msg-neg', true);
        $msg = ConversationMessage::where('conversation_id', 'fb-msg-neg')->first();

        $this->postJson('/api/feedback/message', [
            'messageId' => $msg->message_id,
            'feedback' => -1,
        ], apiHeaders())
            ->assertStatus(200)
            ->assertJson(['feedback' => -1]);
    });

    it('stores feedback with a comment', function () {
        $conv = makeConversation('fb-msg-comment', true);
        $msg = ConversationMessage::where('conversation_id', 'fb-msg-comment')->first();

        $this->postJson('/api/feedback/message', [
            'messageId' => $msg->message_id,
            'feedback' => 1,
            'comment' => 'Very helpful!',
        ], apiHeaders())
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('conversation_messages', [
            'message_id' => $msg->message_id,
            'feedback_comment' => 'Very helpful!',
        ]);
    });

    it('returns 404 when message does not exist', function () {
        $this->postJson('/api/feedback/message', [
            'messageId' => 'non-existent-uuid',
            'feedback' => 1,
        ], apiHeaders())
            ->assertStatus(404);
    });

    it('returns 422 when messageId is missing', function () {
        $this->postJson('/api/feedback/message', ['feedback' => 1], apiHeaders())
            ->assertStatus(422);
    });

    it('returns 422 when feedback value is missing', function () {
        $conv = makeConversation('fb-msg-no-val', true);
        $msg = ConversationMessage::where('conversation_id', 'fb-msg-no-val')->first();

        $this->postJson('/api/feedback/message', ['messageId' => $msg->message_id], apiHeaders())
            ->assertStatus(422);
    });

    it('returns 422 when feedback value is out of range (e.g. 2)', function () {
        $conv = makeConversation('fb-msg-bad-val', true);
        $msg = ConversationMessage::where('conversation_id', 'fb-msg-bad-val')->first();

        $this->postJson('/api/feedback/message', [
            'messageId' => $msg->message_id,
            'feedback' => 2,
        ], apiHeaders())
            ->assertStatus(422);
    });

    it('returns 422 when feedback value is a string', function () {
        $conv = makeConversation('fb-msg-str-val', true);
        $msg = ConversationMessage::where('conversation_id', 'fb-msg-str-val')->first();

        $this->postJson('/api/feedback/message', [
            'messageId' => $msg->message_id,
            'feedback' => 'thumbs_up',
        ], apiHeaders())
            ->assertStatus(422);
    });

    it('returns the messageId (UUID) in the response', function () {
        $conv = makeConversation('fb-msg-uuid-resp', true);
        $msg = ConversationMessage::where('conversation_id', 'fb-msg-uuid-resp')->first();

        $response = $this->postJson('/api/feedback/message', [
            'messageId' => $msg->message_id,
            'feedback' => 1,
        ], apiHeaders());

        $response->assertJsonPath('message_id', $msg->message_id);
    });

    it('returns 401 without auth', function () {
        $conv = makeConversation('fb-msg-noauth', true);
        $msg = ConversationMessage::where('conversation_id', 'fb-msg-noauth')->first();

        $this->postJson('/api/feedback/message', [
            'messageId' => $msg->message_id,
            'feedback' => 1,
        ])
            ->assertStatus(401);
    });
});

// ────────────────────────────────────────────────────
// POST /api/feedback/conversation
// ────────────────────────────────────────────────────

describe('POST /api/feedback/conversation', function () {
    it('stores positive feedback for a conversation', function () {
        makeConversation('fb-conv-pos');

        $this->postJson('/api/feedback/conversation', [
            'conversationId' => 'fb-conv-pos',
            'feedback' => 1,
        ], apiHeaders())
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'feedback' => 1,
            ]);

        $this->assertDatabaseHas('conversations', [
            'conversation_id' => 'fb-conv-pos',
            'feedback' => FeedbackEnum::POSITIVE->value,
        ]);
    });

    it('stores neutral feedback for a conversation', function () {
        makeConversation('fb-conv-neutral');

        $this->postJson('/api/feedback/conversation', [
            'conversationId' => 'fb-conv-neutral',
            'feedback' => 0,
        ], apiHeaders())
            ->assertStatus(200)
            ->assertJson(['feedback' => 0]);
    });

    it('stores negative feedback for a conversation', function () {
        makeConversation('fb-conv-neg');

        $this->postJson('/api/feedback/conversation', [
            'conversationId' => 'fb-conv-neg',
            'feedback' => -1,
        ], apiHeaders())
            ->assertStatus(200)
            ->assertJson(['feedback' => -1]);
    });

    it('stores feedback with a comment', function () {
        makeConversation('fb-conv-comment');

        $this->postJson('/api/feedback/conversation', [
            'conversationId' => 'fb-conv-comment',
            'feedback' => -1,
            'comment' => 'Did not help',
        ], apiHeaders())
            ->assertStatus(200);

        $this->assertDatabaseHas('conversations', [
            'conversation_id' => 'fb-conv-comment',
            'feedback_comment' => 'Did not help',
        ]);
    });

    it('returns 404 when conversation does not exist', function () {
        $this->postJson('/api/feedback/conversation', [
            'conversationId' => 'ghost-conv',
            'feedback' => 1,
        ], apiHeaders())
            ->assertStatus(404);
    });

    it('returns 422 when conversationId is missing', function () {
        $this->postJson('/api/feedback/conversation', ['feedback' => 1], apiHeaders())
            ->assertStatus(422);
    });

    it('returns 422 when feedback value is missing', function () {
        makeConversation('fb-conv-no-val');

        $this->postJson('/api/feedback/conversation', [
            'conversationId' => 'fb-conv-no-val',
        ], apiHeaders())
            ->assertStatus(422);
    });

    it('returns 422 when feedback value is out of range (e.g. 5)', function () {
        makeConversation('fb-conv-bad-val');

        $this->postJson('/api/feedback/conversation', [
            'conversationId' => 'fb-conv-bad-val',
            'feedback' => 5,
        ], apiHeaders())
            ->assertStatus(422);
    });

    it('returns the conversationId in the response', function () {
        makeConversation('fb-conv-id-resp');

        $this->postJson('/api/feedback/conversation', [
            'conversationId' => 'fb-conv-id-resp',
            'feedback' => 1,
        ], apiHeaders())
            ->assertJsonPath('conversation_id', 'fb-conv-id-resp');
    });

    it('can update feedback from positive to negative', function () {
        $conv = makeConversation('fb-conv-update');
        $conv->setFeedback(FeedbackEnum::POSITIVE);

        $this->postJson('/api/feedback/conversation', [
            'conversationId' => 'fb-conv-update',
            'feedback' => -1,
        ], apiHeaders())
            ->assertStatus(200)
            ->assertJson(['feedback' => -1]);

        $this->assertDatabaseHas('conversations', [
            'conversation_id' => 'fb-conv-update',
            'feedback' => FeedbackEnum::NEGATIVE->value,
        ]);
    });

    it('returns 401 without auth', function () {
        makeConversation('fb-conv-noauth');

        $this->postJson('/api/feedback/conversation', [
            'conversationId' => 'fb-conv-noauth',
            'feedback' => 1,
        ])
            ->assertStatus(401);
    });
});

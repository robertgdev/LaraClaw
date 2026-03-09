<?php

use App\DTOs\ConversationStateDTO;
use App\Services\ConversationStateManagerService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->manager = new ConversationStateManagerService;

    // Clear cache before each test
    Cache::flush();
});

/**
 * Helper to create a ConversationStateDTO
 */
function createConversationDTO(array $overrides = []): ConversationStateDTO
{
    $data = array_merge([
        'channel' => 'telegram',
        'sender' => 'Test User',
        'sender_id' => 'user-123',
        'original_message' => 'Hello!',
        'message_id' => 'msg-123',
        'team_context' => ['teamId' => 'team-1', 'team' => ['name' => 'Team 1', 'agents' => []]],
    ], $overrides);

    $dto = new ConversationStateDTO(
        channel: $data['channel'],
        sender: $data['sender'],
        senderId: $data['sender_id'] ?? null,
        originalMessage: $data['original_message'],
        messageId: $data['message_id'] ?? null,
        teamContext: $data['team_context'],
        maxMessages: $data['max_messages'] ?? null,
    );

    // Allow overriding the generated ID
    if (isset($data['id'])) {
        $dto->id = $data['id'];
    }

    // Allow setting initial pending count
    if (isset($data['pending'])) {
        $dto->pending = (int) $data['pending'];
    }

    // Allow setting initial responses
    if (isset($data['responses'])) {
        $dto->responses = $data['responses'];
    }

    return $dto;
}

describe('ConversationStateManager', function () {
    describe('create', function () {
        it('creates a new conversation state', function () {
            // Arrange
            $conversation = createConversationDTO([
                'sender' => 'John Doe',
            ]);

            // Act
            $result = $this->manager->create($conversation);

            // Assert
            expect($result)->toBeInstanceOf(ConversationStateDTO::class)
                ->and($result->channel)->toBe('telegram')
                ->and($result->sender)->toBe('John Doe');
        });

        it('sets maxMessages from data when provided', function () {
            // Arrange
            $conversation = createConversationDTO([
                'max_messages' => 100,
            ]);

            // Act
            $result = $this->manager->create($conversation);

            // Assert
            expect($result)->toBeInstanceOf(ConversationStateDTO::class)
                ->and($result->maxMessages)->toBe(100);
        });
    });

    describe('store', function () {
        it('stores conversation state in cache', function () {
            // Arrange
            $conversation = createConversationDTO();
            $conversation->id = 'test-conv-1';

            // Act
            $result = $this->manager->store($conversation);

            // Assert
            expect($result)->toBeTrue();

            // Verify it's actually in cache by retrieving it
            $cached = $this->manager->get('test-conv-1');
            expect($cached)->not->toBeNull()
                ->and($cached->id)->toBe('test-conv-1');
        });
    });

    describe('get', function () {
        it('returns conversation state when found', function () {
            // Arrange - use manager to create and store
            $conversation = createConversationDTO();
            $conv = $this->manager->create($conversation);
            $id = $conv->id;

            // Act
            $result = $this->manager->get($id);

            // Assert
            expect($result)->toBeInstanceOf(ConversationStateDTO::class)
                ->and($result->id)->toBe($id);
        });

        it('returns null when conversation not found', function () {
            // Act
            $result = $this->manager->get('nonexistent');

            // Assert
            expect($result)->toBeNull();
        });
    });

    describe('exists', function () {
        it('returns true when conversation exists', function () {
            // Arrange - use manager to create
            $conversation = createConversationDTO(['id' => 'existing-conv']);
            $this->manager->create($conversation);

            // Act
            $result = $this->manager->exists('existing-conv');

            // Assert
            expect($result)->toBeTrue();
        });

        it('returns false when conversation does not exist', function () {
            // Act
            $result = $this->manager->exists('nonexistent-conv');

            // Assert
            expect($result)->toBeFalse();
        });
    });

    describe('update', function () {
        it('updates conversation state', function () {
            // Arrange - create first
            $conversation = createConversationDTO([
                'id' => 'test-conv-1',
                'sender' => 'Original Name',
            ]);
            $conversation = $this->manager->create($conversation);

            // Modify
            $conversation->sender = 'Updated Name';

            // Act
            $result = $this->manager->update($conversation);

            // Assert
            expect($result)->toBeTrue();

            // Verify update persisted
            $updated = $this->manager->get('test-conv-1');
            expect($updated->sender)->toBe('Updated Name');
        });
    });

    describe('delete', function () {
        it('deletes conversation state from cache', function () {
            // Arrange - create first
            $conversation = createConversationDTO(['id' => 'test-conv-1']);
            $this->manager->create($conversation);

            // Act
            $result = $this->manager->delete('test-conv-1');

            // Assert
            expect($result)->toBeTrue()
                ->and($this->manager->exists('test-conv-1'))->toBeFalse();
        });
    });

    describe('getOrCreate', function () {
        it('returns existing conversation when found', function () {
            // Arrange - create first
            $conversation = createConversationDTO([
                'id' => 'existing-conv',
                'sender' => 'Test User',
            ]);
            $this->manager->create($conversation);

            // Act
            $newConversation = createConversationDTO([
                'sender' => 'Different User',
            ]);
            $result = $this->manager->getOrCreate('existing-conv', $newConversation);

            // Assert
            expect($result)->toBeInstanceOf(ConversationStateDTO::class)
                ->and($result->id)->toBe('existing-conv')
                ->and($result->sender)->toBe('Test User'); // Original sender preserved
        });

        it('creates new conversation when not found', function () {
            // Arrange
            $conversation = createConversationDTO([
                'sender' => 'New User',
            ]);

            // Act
            $result = $this->manager->getOrCreate('new-conv', $conversation);

            // Assert
            expect($result)->toBeInstanceOf(ConversationStateDTO::class)
                ->and($result->id)->toBe('new-conv');
        });
    });

    describe('addResponse', function () {
        it('adds response to existing conversation', function () {
            // Arrange - create conversation first
            $conversation = createConversationDTO(['pending' => 1]);
            $conv = $this->manager->create($conversation);
            $id = $conv->id;

            $agentId = 'agent-1';
            $response = 'Hello back!';
            $agentName = 'Agent One';

            // Act
            $result = $this->manager->addResponse($id, $agentId, $response, $agentName);

            // Assert
            expect($result)->toBeInstanceOf(ConversationStateDTO::class)
                ->and($result->responses)->toHaveCount(1)
                ->and($result->responses[0]['agentId'])->toBe($agentId);
        });

        it('returns null when conversation not found', function () {
            // Act
            $result = $this->manager->addResponse('nonexistent', 'agent-1', 'response');

            // Assert
            expect($result)->toBeNull();
        });
    });

    describe('addFiles', function () {
        it('adds files to existing conversation', function () {
            // Arrange - create conversation first
            $conversation = createConversationDTO();
            $conv = $this->manager->create($conversation);
            $id = $conv->id;

            $files = ['/tmp/file1.pdf', '/tmp/file2.png'];

            // Act
            $result = $this->manager->addFiles($id, $files);

            // Assert
            expect($result)->toBeInstanceOf(ConversationStateDTO::class)
                ->and($result->files)->toBe($files);
        });

        it('returns null when conversation not found', function () {
            // Act
            $result = $this->manager->addFiles('nonexistent', []);

            // Assert
            expect($result)->toBeNull();
        });
    });

    describe('incrementPending', function () {
        it('increments pending counter', function () {
            // Arrange - create conversation first
            $conversation = createConversationDTO(['pending' => 0]);
            $conv = $this->manager->create($conversation);
            $id = $conv->id;

            // Act
            $result = $this->manager->incrementPending($id);

            // Assert
            expect($result)->toBeInstanceOf(ConversationStateDTO::class)
                ->and($result->pending)->toBe(1);
        });

        it('increments by specified count', function () {
            // Arrange - create conversation first
            $conversation = createConversationDTO(['pending' => 0]);
            $conv = $this->manager->create($conversation);
            $id = $conv->id;

            // Act
            $result = $this->manager->incrementPending($id, 3);

            // Assert
            expect($result)->toBeInstanceOf(ConversationStateDTO::class)
                ->and($result->pending)->toBe(3);
        });
    });

    describe('decrementPending', function () {
        it('decrements pending counter', function () {
            // Arrange - create conversation first
            $conversation = createConversationDTO(['pending' => 2]);
            $conv = $this->manager->create($conversation);
            $id = $conv->id;

            // Act
            $result = $this->manager->decrementPending($id);

            // Assert
            expect($result)->toBeInstanceOf(ConversationStateDTO::class)
                ->and($result->pending)->toBe(1);
        });
    });

    describe('isComplete', function () {
        it('returns true when pending is zero', function () {
            // Arrange - create conversation first
            $conversation = createConversationDTO(['pending' => 0]);
            $conv = $this->manager->create($conversation);
            $id = $conv->id;

            // Act
            $result = $this->manager->isComplete($id);

            // Assert
            expect($result)->toBeTrue();
        });

        it('returns false when pending is greater than zero', function () {
            // Arrange - create conversation first
            $conversation = createConversationDTO(['pending' => 2]);
            $conv = $this->manager->create($conversation);
            $id = $conv->id;

            // Act
            $result = $this->manager->isComplete($id);

            // Assert
            expect($result)->toBeFalse();
        });

        it('returns false when conversation not found', function () {
            // Act
            $result = $this->manager->isComplete('nonexistent');

            // Assert
            expect($result)->toBeFalse();
        });
    });

    describe('complete', function () {
        it('completes and deletes conversation', function () {
            // Arrange - create conversation first
            $conversation = createConversationDTO([
                'responses' => [['agentId' => 'agent1', 'response' => 'Hi!']],
                'pending' => 0,
            ]);
            $conv = $this->manager->create($conversation);
            $id = $conv->id;

            // Act
            $result = $this->manager->complete($id);

            // Assert
            expect($result)->toBeInstanceOf(ConversationStateDTO::class)
                ->and($this->manager->exists($id))->toBeFalse();
        });

        it('returns null when conversation not found', function () {
            // Act
            $result = $this->manager->complete('nonexistent');

            // Assert
            expect($result)->toBeNull();
        });
    });

    describe('setTtl', function () {
        it('sets the TTL value', function () {
            // Act
            $result = $this->manager->setTtl(7200);

            // Assert
            expect($result)->toBeInstanceOf(ConversationStateManagerService::class)
                ->and($this->manager->getTtl())->toBe(7200);
        });
    });

    describe('getTtl', function () {
        it('returns the current TTL value', function () {
            // Act
            $result = $this->manager->getTtl();

            // Assert
            expect($result)->toBeInt();
        });
    });
});

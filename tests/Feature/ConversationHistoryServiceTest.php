<?php

use App\Enums\ChannelEnum;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Team;
use App\Services\ConversationHistoryService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->settings = app(SettingsService::class);
    $this->conversationHistoryService = new ConversationHistoryService($this->settings);

    // Clean up conversations before each test
    Conversation::query()->delete();
    Team::query()->delete();
    Agent::query()->delete();
});

describe('ConversationHistoryService', function () {
    describe('saveConversation', function () {
        it('saves a conversation to the database', function () {
            // Arrange
            $channel = 'telegram';
            $sender = 'John Doe';
            $userMessage = 'Hello, how are you?';

            // Create the agent first (required for foreign key)
            Agent::createFromConfig('agent1', ['name' => 'Agent One']);

            $responses = [
                ['agentId' => 'agent1', 'agentName' => 'Agent One', 'response' => 'I am well!'],
            ];

            // Act
            $result = $this->conversationHistoryService->saveConversation(
                $channel,
                $sender,
                $userMessage,
                $responses
            );

            // Assert
            expect($result)->toBeString();

            $conversation = Conversation::where('conversation_id', $result)->first();
            expect($conversation)->not->toBeNull()
                ->and($conversation->sender)->toBe($sender);

            // Check user message is stored in the first incoming message
            $firstMessage = $conversation->getFirstUserMessage();
            expect($firstMessage)->not->toBeNull()
                ->and($firstMessage->message)->toBe($userMessage);
        });

        it('saves a team conversation with team_id', function () {
            // Arrange - create team first
            Agent::createFromConfig('leader-1', ['name' => 'Leader']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'leader_agent' => 'leader-1',
                'agents' => ['leader-1'],
            ]);

            $channel = 'discord';
            $sender = 'Jane Doe';
            $userMessage = 'Team message';
            $responses = [];
            $teamId = 'team-1';

            // Act
            $result = $this->conversationHistoryService->saveConversation(
                $channel,
                $sender,
                $userMessage,
                $responses,
                $teamId
            );

            // Assert
            expect($result)->toBeString();

            $conversation = Conversation::where('conversation_id', $result)->first();
            expect($conversation)->not->toBeNull()
                ->and($conversation->team_id)->toBe($teamId);
        });

        it('saves a conversation with files', function () {
            // Arrange
            $channel = 'telegram';
            $sender = 'User';
            $userMessage = 'Check this file';
            $responses = [];
            $files = ['/tmp/file1.pdf', '/tmp/file2.png'];

            // Act
            $result = $this->conversationHistoryService->saveConversation(
                $channel,
                $sender,
                $userMessage,
                $responses,
                null,
                $files
            );

            // Assert
            expect($result)->toBeString();

            $conversation = Conversation::where('conversation_id', $result)->first();
            expect($conversation)->not->toBeNull();

            // Check files are stored in the first incoming message
            $firstMessage = $conversation->getFirstUserMessage();
            expect($firstMessage)->not->toBeNull()
                ->and($firstMessage->files)->toBe($files);
        });
    });

    describe('saveSingleAgentConversation', function () {
        it('saves a single agent conversation', function () {
            // Arrange
            $channel = 'telegram';
            $sender = 'Test User';
            $userMessage = 'Hello';
            $agentId = 'agent1';
            $agentName = 'Agent One';
            $response = 'Hello back!';

            // Create the agent first (required for foreign key)
            Agent::createFromConfig('agent1', ['name' => 'Agent One']);

            // Act
            $result = $this->conversationHistoryService->saveSingleAgentConversation(
                $channel,
                $sender,
                $userMessage,
                $agentId,
                $agentName,
                $response
            );

            // Assert
            expect($result)->toBeString();

            $conversation = Conversation::where('conversation_id', $result)->first();
            expect($conversation)->not->toBeNull()
                ->and($conversation->sender)->toBe($sender);

            // Check user message is stored in the first incoming message
            $firstMessage = $conversation->getFirstUserMessage();
            expect($firstMessage)->not->toBeNull()
                ->and($firstMessage->message)->toBe($userMessage);
        });
    });

    describe('saveTeamConversation', function () {
        it('saves a team conversation', function () {
            // Arrange - create team first
            Agent::createFromConfig('agent1', ['name' => 'Agent 1']);
            Agent::createFromConfig('agent2', ['name' => 'Agent 2']);
            Team::createFromConfig('team-alpha', [
                'name' => 'Team Alpha',
                'leader_agent' => 'agent1',
                'agents' => ['agent1', 'agent2'],
            ]);

            $channel = 'discord';
            $sender = 'Team Lead';
            $userMessage = 'Team update';
            $teamId = 'team-alpha';
            $responses = [
                ['agentId' => 'agent1', 'agentName' => 'Agent 1', 'response' => 'Acknowledged'],
                ['agentId' => 'agent2', 'agentName' => 'Agent 2', 'response' => 'Noted'],
            ];

            // Act
            $result = $this->conversationHistoryService->saveTeamConversation(
                $channel,
                $sender,
                $userMessage,
                $teamId,
                $responses
            );

            // Assert
            expect($result)->toBeString();

            $conversation = Conversation::where('conversation_id', $result)->first();
            expect($conversation)->not->toBeNull()
                ->and($conversation->team_id)->toBe($teamId);
        });
    });

    describe('getRecentHistory', function () {
        it('returns recent conversations', function () {
            // Arrange - create some conversations
            Conversation::factory()->count(3)->create();

            // Act
            $result = $this->conversationHistoryService->getRecentHistory();

            // Assert
            expect($result)->toBeArray()
                ->toHaveCount(3);
        });

        it('filters by team_id when provided', function () {
            // Arrange - create teams first
            Agent::createFromConfig('leader-1', ['name' => 'Leader 1']);
            Agent::createFromConfig('leader-2', ['name' => 'Leader 2']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'leader_agent' => 'leader-1',
                'agents' => ['leader-1'],
            ]);
            Team::createFromConfig('team-2', [
                'name' => 'Team 2',
                'leader_agent' => 'leader-2',
                'agents' => ['leader-2'],
            ]);

            // Create conversations with different team_ids
            Conversation::factory()->create(['team_id' => 'team-1']);
            Conversation::factory()->create(['team_id' => 'team-1']);
            Conversation::factory()->create(['team_id' => 'team-2']);

            // Act
            $result = $this->conversationHistoryService->getRecentHistory(10, 'team-1');

            // Assert
            expect($result)->toBeArray()
                ->toHaveCount(2);
        });
    });

    describe('search', function () {
        it('searches conversations by query', function () {
            // Arrange - create conversations with messages
            $conv1 = Conversation::create([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'User 1',
            ]);
            $conv1->addUserMessage('Hello world');

            $conv2 = Conversation::create([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'User 2',
            ]);
            $conv2->addUserMessage('Goodbye world');

            $conv3 = Conversation::create([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'User 3',
            ]);
            $conv3->addUserMessage('Something else');

            // Act
            $result = $this->conversationHistoryService->search('world');

            // Assert
            expect($result)->toBeArray()
                ->toHaveCount(2);
        });

        it('searches with team filter', function () {
            // Arrange - create teams first
            Agent::createFromConfig('leader-1', ['name' => 'Leader 1']);
            Agent::createFromConfig('leader-2', ['name' => 'Leader 2']);
            Team::createFromConfig('team-1', [
                'name' => 'Team 1',
                'leader_agent' => 'leader-1',
                'agents' => ['leader-1'],
            ]);
            Team::createFromConfig('team-2', [
                'name' => 'Team 2',
                'leader_agent' => 'leader-2',
                'agents' => ['leader-2'],
            ]);

            // Create conversations with unique search terms
            $conv1 = Conversation::create([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'User 1',
                'team_id' => 'team-1',
            ]);
            $conv1->addUserMessage('alpha unique search term');

            $conv2 = Conversation::create([
                'channel' => ChannelEnum::TELEGRAM,
                'sender' => 'User 2',
                'team_id' => 'team-2',
            ]);
            $conv2->addUserMessage('beta unique search term');

            // Act
            $result = $this->conversationHistoryService->search('alpha', 20, 'team-1');

            // Assert
            expect($result)->toBeArray()
                ->toHaveCount(1);
        });
    });

    describe('getConversation', function () {
        it('returns conversation data when found', function () {
            // Arrange
            $conversation = Conversation::factory()->create();

            // Act
            $result = $this->conversationHistoryService->getConversation($conversation->conversation_id);

            // Assert
            expect($result)->toBeArray()
                ->and($result['conversation_id'])->toBe($conversation->conversation_id);
        });

        it('returns null when conversation not found', function () {
            // Act
            $result = $this->conversationHistoryService->getConversation('nonexistent');

            // Assert
            expect($result)->toBeNull();
        });
    });

    describe('cleanupOldHistory', function () {
        it('deletes old conversations and returns count', function () {
            // Arrange - create old and new conversations
            Conversation::factory()->create([
                'created_at' => now()->subDays(60),
            ]);
            Conversation::factory()->create([
                'created_at' => now()->subDays(60),
            ]);
            Conversation::factory()->create([
                'created_at' => now()->subDays(10),
            ]);

            // Act
            $result = $this->conversationHistoryService->cleanupOldHistory(30);

            // Assert
            expect($result)->toBe(2);
            expect(Conversation::count())->toBe(1);
        });
    });

    describe('getStatistics', function () {
        it('returns conversation statistics', function () {
            // Arrange
            Conversation::factory()->count(5)->create([
                'channel' => ChannelEnum::TELEGRAM,
            ]);
            Conversation::factory()->count(3)->create([
                'channel' => ChannelEnum::DISCORD,
            ]);

            // Act
            $result = $this->conversationHistoryService->getStatistics();

            // Assert
            expect($result)->toBeArray()
                ->toHaveKey('total_conversations')
                ->toHaveKey('by_channel')
                ->toHaveKey('by_team')
                ->toHaveKey('recent_24h')
                ->toHaveKey('recent_7d')
                ->toHaveKey('recent_30d')
                ->and($result['total_conversations'])->toBe(8);
        });
    });

    describe('getChatsDir', function () {
        it('returns the chats directory path', function () {
            // Act
            $result = $this->conversationHistoryService->getChatsDir();

            // Assert
            expect($result)->toBeString()
                ->toEndWith('/chats');
        });
    });

    describe('getHistoryFiles', function () {
        it('returns empty array when directory does not exist', function () {
            // Arrange - use a non-existent directory
            $this->settings->set('workspace.path', '/tmp/nonexistent_laraclaw_test_'.Str::random(8));
            $service = new ConversationHistoryService($this->settings);

            // Act
            $result = $service->getHistoryFiles();

            // Assert
            expect($result)->toBeArray()
                ->toBeEmpty();
        });

        it('returns array of file paths when directory exists', function () {
            // Arrange
            $testDir = $this->settings->getWorkspacePath().'/chats';
            File::ensureDirectoryExists($testDir);
            File::put($testDir.'/chat1.md', 'content');
            File::put($testDir.'/chat2.md', 'content');

            $service = new ConversationHistoryService($this->settings);

            // Act
            $result = $service->getHistoryFiles();

            // Assert
            expect($result)->toBeArray()
                ->toHaveCount(2);

            // Cleanup
            File::deleteDirectory($testDir);
        });

        it('filters by team_id when provided', function () {
            // Arrange
            $testDir = '/tmp/laraclaw_test_chats_team_'.Str::random(8);
            $teamDir = $testDir.'/team-1';
            File::ensureDirectoryExists($teamDir);
            File::put($teamDir.'/chat1.md', 'content');

            $this->settings->set('workspace.path', dirname($testDir));
            $service = new ConversationHistoryService($this->settings);

            // Act
            $result = $service->getHistoryFiles('team-1');

            // Assert
            expect($result)->toBeArray();

            // Cleanup
            File::deleteDirectory($testDir);
        });
    });
});

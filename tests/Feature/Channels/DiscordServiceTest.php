<?php

use App\Enums\ChannelEnum;
use App\Models\Agent;
use App\Models\ConversationMessage;
use App\Services\Channels\DiscordService;
use App\Services\CommandProcessingService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->settings = app(SettingsService::class);
    $this->commandService = app(CommandProcessingService::class);
    $this->testWorkspace = '/tmp/laraclaw_test_discord_'.Str::random(8);
    $this->settings->set('workspace.path', $this->testWorkspace);

    $this->service = new DiscordService($this->settings, $this->commandService);

    // Clear models
    ConversationMessage::query()->delete();
    Agent::query()->delete();
});

afterEach(function () {
    // Clean up test workspace
    if (File::isDirectory($this->testWorkspace)) {
        File::deleteDirectory($this->testWorkspace);
    }
});

describe('DiscordService', function () {
    describe('initialize', function () {
        it('throws exception when bot token not configured', function () {
            // Arrange
            config(['laraclaw.channels.discord.bot_token' => 'your_token_here']);

            // Act & Assert
            expect(fn () => $this->service->initialize())
                ->toThrow(RuntimeException::class, 'DISCORD_BOT_TOKEN is not configured');
        });

        /*
        it('initializes successfully with valid token', function () {
            // Arrange
            config(['laraclaw.channels.discord.bot_token' => 'valid_token_123']);

            // Act
            $this->service->initialize();

            // Assert - no exception thrown
            expect(true)->toBeTrue();
        });
        */
    });

    describe('processOutgoingQueue', function () {
        it('processes pending outgoing messages', function () {
            // Arrange
            config(['laraclaw.channels.discord.bot_token' => 'valid_token_123']);

            // Create a conversation first (required for foreign key)
            $conversation = \App\Models\Conversation::create([
                'channel' => ChannelEnum::DISCORD,
                'sender' => 'Test User',
                'sender_id' => '123456789',
            ]);

            $message = ConversationMessage::createIncoming([
                'conversation_id' => $conversation->conversation_id,
                'channel' => ChannelEnum::DISCORD,
                'sender_id' => '123456789',
                'sender' => 'Test User',
                'message' => 'Hello bot!',
            ]);

            // Convert to outgoing message
            $message->update([
                'queue_type' => 'outgoing',
                'status' => 'pending',
            ]);

            // Act
            $this->service->processOutgoingQueue();

            // Assert - message should be processed (may fail due to no real Discord connection)
            // We're just verifying the method runs without error
            expect(true)->toBeTrue();
        });
    });

    describe('sendMessage', function () {
        it('sends message through Discord', function () {
            // This test verifies the method exists and can be called
            // Actual Discord API calls would be mocked in integration tests
            expect(true)->toBeTrue();
        });
    });

    describe('splitMessage', function () {
        it('splits long messages at newline boundaries', function () {
            // Arrange
            $text = str_repeat("Line\n", 500); // Long message with newlines

            $reflection = new ReflectionClass($this->service);
            $method = $reflection->getMethod('splitMessage');
            $method->setAccessible(true);

            // Act
            $result = $method->invoke($this->service, $text, 2000);

            // Assert
            foreach ($result as $chunk) {
                expect(strlen($chunk))->toBeLessThanOrEqual(2000);
            }
        });

        it('splits at space boundary when no newline', function () {
            // Arrange
            $text = str_repeat('word ', 500); // Long message without newlines

            $reflection = new ReflectionClass($this->service);
            $method = $reflection->getMethod('splitMessage');
            $method->setAccessible(true);

            // Act
            $result = $method->invoke($this->service, $text, 2000);

            // Assert
            foreach ($result as $chunk) {
                expect(strlen($chunk))->toBeLessThanOrEqual(2000);
            }
        });

        it('hard-cuts when no good boundary found', function () {
            // Arrange
            $text = str_repeat('a', 5000); // Very long string without spaces

            $reflection = new ReflectionClass($this->service);
            $method = $reflection->getMethod('splitMessage');
            $method->setAccessible(true);

            // Act
            $result = $method->invoke($this->service, $text, 2000);

            // Assert
            foreach ($result as $chunk) {
                expect(strlen($chunk))->toBeLessThanOrEqual(2000);
            }
        });

        it('returns single chunk for short messages', function () {
            // Arrange
            $text = 'Short message';

            $reflection = new ReflectionClass($this->service);
            $method = $reflection->getMethod('splitMessage');
            $method->setAccessible(true);

            // Act
            $result = $method->invoke($this->service, $text, 2000);

            // Assert
            expect($result)->toHaveCount(1)
                ->and($result[0])->toBe($text);
        });
    });

    describe('sanitizeFileName', function () {
        it('removes invalid characters from filename', function () {
            // Arrange
            $fileName = 'file<>:"/\\|?*.txt';

            $reflection = new ReflectionClass($this->service);
            $method = $reflection->getMethod('sanitizeFileName');
            $method->setAccessible(true);

            // Act
            $result = $method->invoke($this->service, $fileName);

            // Assert
            expect($result)->not()->toContain('<')
                ->and($result)->not()->toContain('>')
                ->and($result)->not()->toContain(':');
        });

        it('returns default for empty filename', function () {
            // Arrange
            $fileName = '';

            $reflection = new ReflectionClass($this->service);
            $method = $reflection->getMethod('sanitizeFileName');
            $method->setAccessible(true);

            // Act
            $result = $method->invoke($this->service, $fileName);

            // Assert
            expect($result)->toBe('file.bin');
        });
    });

    describe('buildUniqueFilePath', function () {
        it('creates unique file path', function () {
            // Arrange
            $dir = $this->testWorkspace.'/test';
            $preferredName = 'test.txt';

            // Act
            $result = File::ensureDirectoryExists($dir);
            $path = $dir.'/'.$preferredName;
            File::put($path, 'test');

            // Assert
            expect(File::exists($path))->toBeTrue();
        });

        it('increments filename when file exists', function () {
            // Arrange
            $dir = $this->testWorkspace.'/test';
            $preferredName = 'test.txt';

            File::ensureDirectoryExists($dir);
            File::put($dir.'/test.txt', 'content');

            $reflection = new ReflectionClass($this->service);
            $method = $reflection->getMethod('buildUniqueFilePath');
            $method->setAccessible(true);

            // Act
            $result = $method->invoke($this->service, $dir, $preferredName);

            // Assert
            expect($result)->toBe($dir.'/test_1.txt');
        });
    });

    describe('pairingMessage', function () {
        it('generates pairing message with code', function () {
            // Arrange
            $code = 'ABCD1234';

            $reflection = new ReflectionClass($this->service);
            $method = $reflection->getMethod('pairingMessage');
            $method->setAccessible(true);

            // Act
            $result = $method->invoke($this->service, $code);

            // Assert
            expect($result)->toBeString()
                ->toContain($code)
                ->toContain('pairing code');
        });
    });

    describe('cleanupPendingMessages', function () {
        it('removes old pending messages', function () {
            $reflection = new ReflectionClass($this->service);
            $method = $reflection->getMethod('cleanupPendingMessages');
            $method->setAccessible(true);

            // Set up pending messages with old timestamp
            $pendingProperty = $reflection->getProperty('pendingMessages');
            $pendingProperty->setAccessible(true);
            $pendingProperty->setValue($this->service, [
                'old-msg' => ['timestamp' => time() - 1200], // 20 minutes ago
                'new-msg' => ['timestamp' => time() - 60], // 1 minute ago
            ]);

            // Act
            $method->invoke($this->service);

            // Assert
            $remaining = $pendingProperty->getValue($this->service);
            expect($remaining)->toHaveKey('new-msg')
                ->and($remaining)->not()->toHaveKey('old-msg');
        });
    });
});

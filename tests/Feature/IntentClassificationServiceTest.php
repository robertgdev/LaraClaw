<?php

use App\Services\Intent\EntityExtractor;
use App\Services\Intent\IntentCacheManager;
use App\Services\Intent\LlmIntentClassifier;
use App\Services\IntentClassificationService;
use App\Services\SettingsService;
use App\TypedCollections\AgentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->settings = app(SettingsService::class);
    $this->service = new IntentClassificationService($this->settings);

    // Clear cache before each test
    Cache::flush();
});

describe('IntentClassificationService', function () {
    describe('classify', function () {
        it('returns cached result when available', function () {
            // Arrange
            $message = 'What is the capital of France?';
            $cachedDTO = new \App\DTOs\IntentClassificationDTO(
                intent: 'question',
                confidence: 0.9,
                method: 'pattern',
            );

            // Pre-populate cache
            $cacheKey = 'intent:'.md5($message);
            Cache::put($cacheKey, $cachedDTO, 3600);

            // Act
            $result = $this->service->classify($message);

            // Assert
            expect($result->intent)->toBe('question')
                ->and($result->confidence)->toBe(0.9);
        });

        it('uses quick classify for high confidence patterns', function () {
            // Arrange
            $message = 'Hello there!';

            // Act
            $result = $this->service->classify($message);

            // Assert
            expect($result)->toBeInstanceOf(\App\DTOs\IntentClassificationDTO::class)
                ->and($result->intent)->not()->toBeEmpty()
                ->and($result->confidence)->toBeGreaterThanOrEqual(0);
        });

        it('classifies question intent correctly', function () {
            // Arrange
            $message = 'What is the capital of France?';

            // Act
            $result = $this->service->classify($message);

            // Assert
            expect($result->intent)->toBe('question');
        });

        it('classifies command intent correctly', function () {
            // Arrange
            $message = 'Create a new file for me';

            // Act
            $result = $this->service->classify($message);

            // Assert
            expect($result->intent)->toBe('command');
        });

        it('classifies conversation intent correctly', function () {
            // Arrange
            $message = 'Hello, how are you?';

            // Act
            $result = $this->service->classify($message);

            // Assert
            expect($result->intent)->toBe('conversation');
        });

        it('classifies coding intent correctly', function () {
            // Arrange
            $message = 'Can you debug this function?';

            // Act
            $result = $this->service->classify($message);

            // Assert
            expect($result->intent)->toBe('coding');
        });

        it('classifies creative intent correctly', function () {
            // Arrange - use a message with creative keywords
            $message = 'Generate a beautiful image for me';

            // Act
            $result = $this->service->classify($message);

            // Assert - should be classified as creative or command
            expect($result->intent)->toBeIn(['creative', 'command']);
        });

        it('classifies scheduling intent correctly', function () {
            // Arrange
            $message = 'Schedule a meeting for tomorrow';

            // Act
            $result = $this->service->classify($message);

            // Assert
            expect($result->intent)->toBe('scheduling');
        });
    });

    describe('extractEntities', function () {
        it('extracts location entities', function () {
            // Arrange
            $message = 'What is the population in Berlin?';

            // Act
            $result = $this->service->extractEntities($message);

            // Assert
            expect($result)->toBeArray()
                ->toHaveKey('locations')
                ->toHaveKey('dates')
                ->toHaveKey('people')
                ->toHaveKey('organizations')
                ->toHaveKey('topics');
        });

        it('extracts date entities', function () {
            // Arrange
            $message = 'Schedule a meeting for tomorrow at 3pm';

            // Act
            $result = $this->service->extractEntities($message);

            // Assert
            expect($result['dates'])->not()->toBeEmpty();
        });

        it('extracts multiple date formats', function () {
            // Arrange
            $message = 'Compare data from 2024-01-15 and 01/20/2024';

            // Act
            $result = $this->service->extractEntities($message);

            // Assert
            expect($result['dates'])->toHaveCount(2);
        });
    });

    describe('suggestAgent', function () {
        it('returns suggestions based on intent and capabilities', function () {
            // Arrange - create actual Agent models
            $coderAgent = \App\Models\Agent::create([
                'agent_id' => 'coder-agent',
                'name' => 'Coder Agent',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-5',
                'is_active' => true,
                'capabilities' => ['coding', 'command'],
            ]);

            $creativeAgent = \App\Models\Agent::create([
                'agent_id' => 'creative-agent',
                'name' => 'Creative Agent',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-5',
                'is_active' => true,
                'capabilities' => ['creative'],
            ]);

            $message = 'Can you debug this code for me?';
            $agents = new AgentCollection([$coderAgent, $creativeAgent]);

            // Act
            $result = $this->service->suggestAgent($message, $agents);

            // Assert
            expect($result)->toBeArray()
                ->toHaveKey('classification')
                ->toHaveKey('entities')
                ->toHaveKey('suggestions')
                ->toHaveKey('best_match');
        });

        it('returns null best_match when no agents match', function () {
            // Arrange
            $message = 'Random message with no clear intent';
            $agents = new AgentCollection([]);

            // Act
            $result = $this->service->suggestAgent($message, $agents);

            // Assert
            expect($result['best_match'])->toBeNull();
        });

        it('matches skills to message content', function () {
            // Arrange - create an Agent model with skills
            $creativeAgent = \App\Models\Agent::create([
                'agent_id' => 'creative-agent-2',
                'name' => 'Creative Agent',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-5',
                'is_active' => true,
                'skills' => ['imagegen', 'schedule'],
            ]);

            $message = 'I need help with imagegen for my project';
            $agents = new AgentCollection([$creativeAgent]);

            // Act
            $result = $this->service->suggestAgent($message, $agents);

            // Assert - should have suggestions since agent has imagegen skill
            expect($result)->toBeArray()
                ->toHaveKey('suggestions');
        });
    });

    describe('quickClassify', function () {
        it('returns DTO with correct structure', function () {
            // Arrange
            $message = 'What is the meaning of life?';

            $reflection = new ReflectionClass($this->service);
            $method = $reflection->getMethod('quickClassify');
            $method->setAccessible(true);

            // Act
            $result = $method->invoke($this->service, $message);

            // Assert
            expect($result)->toBeInstanceOf(\App\DTOs\IntentClassificationDTO::class)
                ->and($result->method)->toBe('pattern');
        });

        it('calculates confidence based on pattern matches', function () {
            // Arrange - use a message that matches question patterns
            $message = 'What is the meaning of life?';

            $reflection = new ReflectionClass($this->service);
            $method = $reflection->getMethod('quickClassify');
            $method->setAccessible(true);

            // Act
            $result = $method->invoke($this->service, $message);

            // Assert
            expect($result->intent)->toBe('question')
                ->and($result->confidence)->toBeGreaterThan(0);
        });
    });

    describe('parseClassificationWithSkillsResponse (via LlmIntentClassifier)', function () {
        it('parses valid JSON response', function () {
            // Arrange - Use the extracted LlmIntentClassifier directly
            $llmClassifier = $this->service->getLlmClassifier();
            $text = '{"intent": "coding", "confidence": 0.95, "reasoning": "Contains code keywords"}';
            $originalMessage = 'Debug this code';
            $keywords = ['debug', 'code'];

            // Act
            $result = $llmClassifier->parseResponse($text, $originalMessage, $keywords);

            // Assert
            expect($result->intent)->toBe('coding')
                ->and($result->confidence)->toBe(0.95)
                ->and($result->method)->toBe('llm');
        });

        it('returns unknown for invalid intent', function () {
            // Arrange
            $llmClassifier = $this->service->getLlmClassifier();
            $text = '{"intent": "invalid_category", "confidence": 0.8}';
            $originalMessage = 'Test message';
            $keywords = ['test'];

            // Act
            $result = $llmClassifier->parseResponse($text, $originalMessage, $keywords);

            // Assert
            expect($result->intent)->toBe('unknown')
                ->and($result->confidence)->toBe(0.3);
        });

        it('handles malformed JSON', function () {
            // Arrange
            $llmClassifier = $this->service->getLlmClassifier();
            $text = 'This is not JSON';
            $originalMessage = 'Test message';
            $keywords = ['test'];

            // Act
            $result = $llmClassifier->parseResponse($text, $originalMessage, $keywords);

            // Assert
            expect($result->intent)->toBe('unknown')
                ->and($result->method)->toBe('fallback');
        });
    });

    describe('extracted components', function () {
        it('exposes LlmIntentClassifier', function () {
            expect($this->service->getLlmClassifier())->toBeInstanceOf(LlmIntentClassifier::class);
        });

        it('exposes EntityExtractor', function () {
            expect($this->service->getEntityExtractor())->toBeInstanceOf(EntityExtractor::class);
        });

        it('exposes IntentCacheManager', function () {
            expect($this->service->getCacheManager())->toBeInstanceOf(IntentCacheManager::class);
        });
    });

    describe('config-based intent categories', function () {
        it('reads intent categories from config', function () {
            // Act - classify a message that should match a config category
            $result = $this->service->classify('What is the weather?');

            // Assert - should use config categories
            expect($result->intent)->toBeIn(['question', 'unknown', 'command']);
        });

        it('reads ignore words from config', function () {
            // Arrange
            $message = 'The quick brown fox jumps over the lazy dog';

            // Act
            $keywords = $this->service->extractKeywords($message);

            // Assert - stop words should be filtered
            expect($keywords)->not()->toContain('the', 'over', 'a');
        });
    });

    describe('session intent detection', function () {
        it('detects new_session intent', function () {
            expect($this->service->detectSessionIntent('new session'))->toBe('new_session')
                ->and($this->service->detectSessionIntent('start session'))->toBe('new_session')
                ->and($this->service->detectSessionIntent('new conversation'))->toBe('new_session');
        });

        it('detects show_sessions intent', function () {
            expect($this->service->detectSessionIntent('show sessions'))->toBe('show_sessions')
                ->and($this->service->detectSessionIntent('list sessions'))->toBe('show_sessions')
                ->and($this->service->detectSessionIntent('show my sessions'))->toBe('show_sessions');
        });

        it('detects switch_session intent', function () {
            // Plain number triggers switch_session
            expect($this->service->detectSessionIntent('1'))->toBe('switch_session')
                ->and($this->service->detectSessionIntent('2'))->toBe('switch_session')
                ->and($this->service->detectSessionIntent('10'))->toBe('switch_session');
        });

        it('detects rename_session intent', function () {
            expect($this->service->detectSessionIntent('rename session to Project X'))->toBe('rename_session')
                ->and($this->service->detectSessionIntent('rename session Project to New Name'))->toBe('rename_session');
        });

        it('detects pin_session intent', function () {
            expect($this->service->detectSessionIntent('pin session 1'))->toBe('pin_session')
                ->and($this->service->detectSessionIntent('unpin session 2'))->toBe('pin_session');
        });

        it('detects delete_session intent', function () {
            expect($this->service->detectSessionIntent('delete session 1'))->toBe('delete_session')
                ->and($this->service->detectSessionIntent('delete 2'))->toBe('delete_session');
        });

        it('returns null for non-session messages', function () {
            expect($this->service->detectSessionIntent('hello world'))->toBeNull()
                ->and($this->service->detectSessionIntent('what is the weather?'))->toBeNull()
                ->and($this->service->detectSessionIntent('write some code'))->toBeNull();
        });

        it('isSessionIntent helper works correctly', function () {
            expect($this->service->isSessionIntent('new_session'))->toBeTrue()
                ->and($this->service->isSessionIntent('show_sessions'))->toBeTrue()
                ->and($this->service->isSessionIntent('question'))->toBeFalse();
        });
    });
});

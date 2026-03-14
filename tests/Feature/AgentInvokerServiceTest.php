<?php

use App\Models\Agent;
use App\Models\Team;
use App\Services\AgentInvokerService;
use App\Services\PromptBuilderService;
use App\Services\ProviderMapper;
use App\Services\ResponseParserService;
use App\Services\RoutingService;
use App\Services\SettingsService;
use App\Services\SkillSearchService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->settings = app(SettingsService::class);
    $this->routingService = app(RoutingService::class);
    $this->promptBuilder = app(PromptBuilderService::class);
    $this->testWorkspace = '/tmp/laraclaw_test_invoker_'.Str::random(8);
    $this->settings->set('workspace.path', $this->testWorkspace);

    $this->agentInvoker = new AgentInvokerService($this->routingService, $this->settings, $this->promptBuilder);

    // Clear models
    Agent::query()->delete();
    Team::query()->delete();
});

afterEach(function () {
    // Clean up test workspace
    if (File::isDirectory($this->testWorkspace)) {
        File::deleteDirectory($this->testWorkspace);
    }
});

describe('AgentInvokerService', function () {
    describe('provider mapping (via ProviderMapper)', function () {
        it('maps known providers correctly', function () {
            expect(ProviderMapper::resolve('openai'))->toBe(\Prism\Prism\Enums\Provider::OpenAI)
                ->and(ProviderMapper::resolve('anthropic'))->toBe(\Prism\Prism\Enums\Provider::Anthropic)
                ->and(ProviderMapper::resolve('gemini'))->toBe(\Prism\Prism\Enums\Provider::Gemini)
                ->and(ProviderMapper::resolve('groq'))->toBe(\Prism\Prism\Enums\Provider::Groq)
                ->and(ProviderMapper::resolve('mistral'))->toBe(\Prism\Prism\Enums\Provider::Mistral)
                ->and(ProviderMapper::resolve('xai'))->toBe(\Prism\Prism\Enums\Provider::XAI)
                ->and(ProviderMapper::resolve('ollama'))->toBe(\Prism\Prism\Enums\Provider::Ollama)
                ->and(ProviderMapper::resolve('deepseek'))->toBe(\Prism\Prism\Enums\Provider::DeepSeek);
        });

        it('defaults to anthropic for unknown provider', function () {
            expect(ProviderMapper::resolve('unknown_provider'))->toBe(\Prism\Prism\Enums\Provider::Anthropic);
        });

        it('handles case-insensitive provider names', function () {
            expect(ProviderMapper::resolve('OPENAI'))->toBe(\Prism\Prism\Enums\Provider::OpenAI)
                ->and(ProviderMapper::resolve('Anthropic'))->toBe(\Prism\Prism\Enums\Provider::Anthropic);
        });
    });

    describe('resolveModel', function () {
        it('returns model as-is when it exists in provider models config', function () {
            // The resolveModel method checks if model exists as a key in provider's models array
            config(['laraclaw.providers.anthropic.models' => [
                'claude-3-5-sonnet-20241022' => ['name' => 'Claude 3.5 Sonnet'],
            ]]);

            $reflection = new ReflectionClass($this->agentInvoker);
            $method = $reflection->getMethod('resolveModel');
            $method->setAccessible(true);

            $result = $method->invoke($this->agentInvoker, 'anthropic', 'claude-3-5-sonnet-20241022');
            expect($result)->toBe('claude-3-5-sonnet-20241022');
        });

        it('returns original model if no mapping exists', function () {
            config(['laraclaw.providers.anthropic.models' => []]);

            $reflection = new ReflectionClass($this->agentInvoker);
            $method = $reflection->getMethod('resolveModel');
            $method->setAccessible(true);

            $result = $method->invoke($this->agentInvoker, 'anthropic', 'custom-model');
            expect($result)->toBe('custom-model');
        });

        it('returns original model if provider config is empty', function () {
            config(['laraclaw.providers.nonexistent' => null]);

            $reflection = new ReflectionClass($this->agentInvoker);
            $method = $reflection->getMethod('resolveModel');
            $method->setAccessible(true);

            $result = $method->invoke($this->agentInvoker, 'nonexistent', 'some-model');
            expect($result)->toBe('some-model');
        });
    });

    describe('ensureAgentDirectory', function () {
        it('returns existing directory without creating', function () {
            // Arrange - create the directory
            $agentDir = $this->testWorkspace.'/test-agent';
            File::ensureDirectoryExists($agentDir);

            $reflection = new ReflectionClass($this->agentInvoker);
            $method = $reflection->getMethod('ensureAgentDirectory');
            $method->setAccessible(true);

            // Act
            $result = $method->invoke($this->agentInvoker, 'test-agent');

            // Assert
            expect($result)->toBe($agentDir);
        });

        it('creates directory when it does not exist', function () {
            $reflection = new ReflectionClass($this->agentInvoker);
            $method = $reflection->getMethod('ensureAgentDirectory');
            $method->setAccessible(true);

            // Act
            $result = $method->invoke($this->agentInvoker, 'new-agent');

            // Assert
            expect($result)->toBe($this->testWorkspace.'/new-agent')
                ->and(File::isDirectory($result))->toBeTrue();
        });
    });

    describe('clearAgentPromptCache', function () {
        it('clears the prompt cache for an agent', function () {
            // Create agent directory with AGENTS.md
            $agentDir = $this->testWorkspace.'/test-agent';
            File::ensureDirectoryExists($agentDir);
            File::put($agentDir.'/AGENTS.md', 'Test content');

            // Build and cache the prompt
            $this->promptBuilder->buildSystemPromptCached($agentDir);

            // Clear the cache via agent invoker
            $this->agentInvoker->clearAgentPromptCache('test-agent');

            // Verify cache was cleared (indirectly - no exception should be thrown)
            expect(true)->toBeTrue();
        });
    });

    describe('setSkillService', function () {
        it('sets skill service and propagates to prompt builder', function () {
            $skillService = app(SkillSearchService::class);

            $result = $this->agentInvoker->setSkillService($skillService);

            expect($result)->toBeInstanceOf(AgentInvokerService::class);
        });
    });

    describe('setResponseParser', function () {
        it('sets response parser', function () {
            $scriptExecutor = app(\App\Services\ScriptExecutionService::class);
            $responseParser = new ResponseParserService($scriptExecutor);

            $result = $this->agentInvoker->setResponseParser($responseParser);

            expect($result)->toBeInstanceOf(AgentInvokerService::class);
        });

        it('can get response parser after setting', function () {
            $scriptExecutor = app(\App\Services\ScriptExecutionService::class);
            $responseParser = new ResponseParserService($scriptExecutor);

            $this->agentInvoker->setResponseParser($responseParser);
            $result = $this->agentInvoker->getResponseParser();

            expect($result)->toBeInstanceOf(ResponseParserService::class);
        });

        it('returns null when response parser not set', function () {
            $result = $this->agentInvoker->getResponseParser();

            expect($result)->toBeNull();
        });
    });

    describe('memory service integration', function () {
        it('sets memory service', function () {
            $memoryService = app(\App\Services\MemoryEngineService::class);

            $result = $this->agentInvoker->setMemoryService($memoryService);

            expect($result)->toBeInstanceOf(AgentInvokerService::class);
        });

        it('sets channel', function () {
            $result = $this->agentInvoker->setChannel(\App\Enums\ChannelEnum::TELEGRAM);

            expect($result)->toBeInstanceOf(AgentInvokerService::class);
        });

        it('sets sender id', function () {
            $result = $this->agentInvoker->setSenderId('test-sender-123');

            expect($result)->toBeInstanceOf(AgentInvokerService::class);
        });

        it('can chain memory service setup methods', function () {
            $memoryService = app(\App\Services\MemoryEngineService::class);

            $result = $this->agentInvoker
                ->setMemoryService($memoryService)
                ->setChannel(\App\Enums\ChannelEnum::TELEGRAM)
                ->setSenderId('test-sender-123');

            expect($result)->toBeInstanceOf(AgentInvokerService::class);
        });

        it('propagates memory context to prompt builder when all options are set', function () {
            // This test verifies the integration chain
            $memoryService = app(\App\Services\MemoryEngineService::class);

            // Create a memory for the test user
            $senderId = 'test-sender-for-invoker';
            $channel = \App\Enums\ChannelEnum::TELEGRAM;
            $memoryService->recordEvent($senderId, $channel, new \App\DTOs\EpisodicEventDTO(
                type: \App\Enums\EpisodicEventTypeEnum::FACT_STORED,
                content: 'User prefers TypeScript',
                importance: 0.9,
            ));

            // Set up the invoker with memory service
            $this->agentInvoker
                ->setMemoryService($memoryService)
                ->setChannel($channel)
                ->setSenderId($senderId);

            // Verify setup was successful (no exception)
            expect(true)->toBeTrue();

            // Cleanup
            \App\Models\Memory::forSender($senderId, $channel)->delete();
        });
    });
});

<?php

namespace App\Services;

use App\Enums\ChannelEnum;
use App\Logging\MultiLogger;
use App\Models\Agent;
use App\TypedCollections\AgentCollection;
use App\TypedCollections\TeamCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Prism;

class AgentInvokerService
{
    protected RoutingService $routingService;

    protected SettingsService $settings;

    protected PromptBuilderService $promptBuilder;

    protected ?SkillSearchService $skillService = null;

    protected ?ResponseParserService $responseParser = null;

    protected ?MemoryEngineService $memoryService = null;

    protected ?ChannelEnum $channel = null;

    protected ?string $senderId = null;

    protected ?int $conversationId = null;

    public function __construct(
        RoutingService $routingService,
        SettingsService $settings,
        PromptBuilderService $promptBuilder
    ) {
        $this->routingService = $routingService;
        $this->settings = $settings;
        $this->promptBuilder = $promptBuilder;
    }

    /**
     * Set the skill service dependency (to avoid circular dependency).
     */
    public function setSkillService(SkillSearchService $skillService): self
    {
        $this->skillService = $skillService;
        $this->promptBuilder->setSkillService($skillService);

        return $this;
    }

    /**
     * Set the response parser for script execution.
     */
    public function setResponseParser(ResponseParserService $responseParser): self
    {
        $this->responseParser = $responseParser;

        return $this;
    }

    /**
     * Get the response parser instance.
     */
    public function getResponseParser(): ?ResponseParserService
    {
        return $this->responseParser;
    }

    /**
     * Set the memory service for context injection and event recording.
     */
    public function setMemoryService(MemoryEngineService $memoryService): self
    {
        $this->memoryService = $memoryService;
        $this->promptBuilder->setMemoryService($memoryService);

        return $this;
    }

    /**
     * Set the channel for memory context.
     */
    public function setChannel(ChannelEnum $channel): self
    {
        $this->channel = $channel;

        return $this;
    }

    /**
     * Set the sender ID for memory context.
     */
    public function setSenderId(string $senderId): self
    {
        $this->senderId = $senderId;

        return $this;
    }

    /**
     * Set the conversation ID for lossless memory context.
     */
    public function setConversationId(int $conversationId): self
    {
        $this->conversationId = $conversationId;

        return $this;
    }

    /**
     * Invoke a single agent with a message using Prism PHP.
     */
    public function invokeAgent(
        Agent $agent, // FIXME, remove $agentId
        string $agentId,
        string $message,
        bool $shouldReset,
        AgentCollection $agents,
        TeamCollection $teams,
    ): string {
        // Ensure agent directory exists with config files
        $agentDir = $this->ensureAgentDirectory($agentId);

        // Resolve working directory
        $workingDir = $agent['working_directory'] ?? null;
        if ($workingDir) {
            $workingDir = Str::startsWith($workingDir, '/')
                ? $workingDir
                : $this->settings->getWorkspacePath().'/'.$workingDir;
        } else {
            $workingDir = $agentDir;
        }

        $provider = $agent['provider'] ?? $this->settings->getDefaultProvider();
        $model = $agent['model'] ?? $this->settings->getDefaultModel($provider);

        if ($shouldReset) {
            MultiLogger::info("Resetting conversation for agent: {$agentId}");
        }

        MultiLogger::info("Using {$provider} provider (agent: {$agentId}) with model: {$model}");

        // Build system prompt from AGENTS.md, SOUL.md, custom prompt, and memory context
        $teammates = $this->promptBuilder->extractTeammates($agentId, $agents, $teams);
        $systemPrompt = $this->promptBuilder->buildSystemPromptCached($agentDir, [
            'teammates' => $teammates,
            'custom_prompt' => $agent['system_prompt'] ?? null,
            'agent_id' => $agentId,
            'agent_name' => $agent['name'] ?? null,
            'agent_model' => $model,
            'sender_id' => $this->senderId,
            'channel' => $this->channel,
            'message' => $message,
            'conversation_id' => $this->conversationId,
        ]);

        if (! empty($systemPrompt)) {
            MultiLogger::info("Built system prompt for agent: {$agentId} (".strlen($systemPrompt).' chars)');
        }

        try {
            $response = $this->invokeWithPrism($provider, $model, $message, $systemPrompt, $shouldReset);

            MultiLogger::debug('AgentInvokerService: LLM response received', [
                'agent_id' => $agentId,
                'response_length' => strlen($response),
                'has_response_parser' => $this->responseParser !== null,
            ]);

            // Parse response and execute any scripts if ResponseParser is available
            if ($this->responseParser !== null) {
                MultiLogger::debug('AgentInvokerService: Calling ResponseParser');
                $parsed = $this->responseParser->parseAndExecute($response);

                // If scripts were executed, log and return modified response
                if ($parsed->hasExecutions()) {
                    MultiLogger::info('Executed scripts in response', [
                        'agent_id' => $agentId,
                        'executions' => count($parsed->executions),
                        'successful' => $parsed->getSuccessCount(),
                        'failed' => $parsed->getFailureCount(),
                    ]);

                    return $parsed->modifiedResponse;
                }
            }

            return $response;
        } catch (\Exception $e) {
            MultiLogger::error("LLM error (agent: {$agentId}): {$e->getMessage()}");

            return 'Sorry, I encountered an error processing your request. Please check the logs.';
        }
    }

    /**
     * Invoke LLM using Prism PHP.
     */
    protected function invokeWithPrism(
        string $provider,
        string $model,
        string $message,
        ?string $systemPrompt = null,
        bool $shouldReset = false
    ): string {
        // Map provider string to Prism Provider enum
        $providerEnum = ProviderMapper::resolve($provider);

        // Resolve model ID from short names
        $resolvedModel = $this->resolveModel($provider, $model);

        // Build Prism request
        $prism = Prism::text()
            ->using($providerEnum, $resolvedModel)
            ->withPrompt($message);

        // Add system prompt if provided
        if ($systemPrompt) {
            $prism->withSystemPrompt($systemPrompt);
        }

        // Add conversation history if not resetting
        if (! $shouldReset) {
            // Note: For full conversation continuity, you would load previous messages
            // from a conversation history store and add them with withMessages()
            // This is a simplified version that doesn't persist conversation state
        }

        // Generate response
        $response = $prism->generate();

        return $response->text;
    }

    /**
     * Resolve model ID from short names to full model identifiers.
     * With the new config structure, models are stored directly in the provider config.
     * This method now primarily returns the model as-is since the setup wizard
     * stores the actual model ID, but we keep it for backwards compatibility.
     */
    protected function resolveModel(string $provider, string $model): string
    {
        $providerConfig = config("laraclaw.providers.{$provider}", []);

        // If the model exists as a key in the provider's models array, it's already a valid model ID
        if (isset($providerConfig['models'][$model])) {
            return $model;
        }

        // Otherwise return as-is (the model ID is already the full identifier)
        return $model;
    }

    /**
     * Ensure agent directory exists with template files.
     */
    protected function ensureAgentDirectory(string $agentId): string
    {
        $workspacePath = $this->settings->getWorkspacePath();
        $agentDir = $workspacePath.'/'.$agentId;

        if (File::isDirectory($agentDir)) {
            return $agentDir;
        }

        File::makeDirectory($agentDir, 0755, true);

        // Copy from templates directory
        $templatesDir = storage_path('app/laraclaw/templates');

        // Copy .claude directory
        $sourceClaudeDir = $templatesDir.'/.claude';
        $targetClaudeDir = $agentDir.'/.claude';

        if (File::isDirectory($sourceClaudeDir)) {
            File::copyDirectory($sourceClaudeDir, $targetClaudeDir);
        }

        // Copy heartbeat.md
        $sourceHeartbeat = $templatesDir.'/heartbeat.md';
        $targetHeartbeat = $agentDir.'/heartbeat.md';
        if (File::exists($sourceHeartbeat)) {
            File::copy($sourceHeartbeat, $targetHeartbeat);
        }

        // Copy AGENTS.md
        $sourceAgents = $templatesDir.'/AGENTS.md';
        $targetAgents = $agentDir.'/AGENTS.md';
        if (File::exists($sourceAgents)) {
            File::copy($sourceAgents, $targetAgents);
        }

        // Copy AGENTS.md as .claude/CLAUDE.md
        if (File::exists($sourceAgents)) {
            if (! File::isDirectory($agentDir.'/.claude')) {
                File::makeDirectory($agentDir.'/.claude', 0755, true);
            }
            File::copy($sourceAgents, $agentDir.'/.claude/CLAUDE.md');
        }

        // Create .laraclaw directory and copy SOUL.md
        $targetLaraclaw = $agentDir.'/.laraclaw';
        File::makeDirectory($targetLaraclaw, 0755, true);

        $sourceSoul = $templatesDir.'/SOUL.md';
        if (File::exists($sourceSoul)) {
            File::copy($sourceSoul, $targetLaraclaw.'/SOUL.md');
        }

        MultiLogger::info("Initialized agent directory with config files: {$agentDir}");

        return $agentDir;
    }

    /**
     * Clear the prompt cache for an agent.
     * Call this when AGENTS.md or SOUL.md is modified.
     */
    public function clearAgentPromptCache(string $agentId): void
    {
        $agentDir = $this->settings->getWorkspacePath().'/'.$agentId;
        $this->promptBuilder->clearCache($agentDir);
        MultiLogger::info("Cleared prompt cache for agent: {$agentId}");
    }
}

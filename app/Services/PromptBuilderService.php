<?php

namespace App\Services;

use App\Enums\ChannelEnum;
use App\Services\Prompt\MemoryContextBuilder;
use App\Services\Prompt\SkillPromptBuilder;
use App\Services\Prompt\TeammatePromptInjector;
use App\TypedCollections\AgentCollection;
use App\TypedCollections\TeamCollection;
use Illuminate\Support\Facades\File;

class PromptBuilderService
{
    protected ?SkillSearchService $skillService = null;

    protected ?MemoryEngineService $memoryService = null;

    protected SkillPromptBuilder $skillPromptBuilder;

    protected TeammatePromptInjector $teammateInjector;

    /**
     * Custom template paths for testing purposes.
     * When set, these override the default storage_path() and resource_path() calls.
     */
    protected ?string $customStorageClawPath = null;

    protected ?string $customResourcesClawPath = null;

    public function __construct()
    {
        $this->skillPromptBuilder = new SkillPromptBuilder;
        $this->teammateInjector = new TeammatePromptInjector;
    }

    /**
     * Set the skill service dependency (to avoid circular dependency).
     */
    public function setSkillService(SkillSearchService $skillService): void
    {
        $this->skillService = $skillService;
        $this->skillPromptBuilder->setSkillService($skillService);
    }

    /**
     * Set the memory service dependency.
     */
    public function setMemoryService(MemoryEngineService $memoryService): void
    {
        $this->memoryService = $memoryService;
    }

    /**
     * Set custom template paths (for testing).
     */
    public function setTemplatePaths(?string $storageClawPath, ?string $resourcesClawPath): void
    {
        $this->customStorageClawPath = $storageClawPath;
        $this->customResourcesClawPath = $resourcesClawPath;
        $this->skillPromptBuilder->setTemplatePaths($storageClawPath, $resourcesClawPath);
    }

    /**
     * Build the system prompt for an agent by combining multiple sources.
     *
     * Sources (in order):
     * 1. AGENTS.md - Core agent instructions and team communication rules
     * 2. SOUL.md - Agent identity, personality, worldview
     * 3. Teammate info - Dynamically injected based on team membership
     * 4. Custom prompt - Agent-specific instructions from database
     * 5. Skills - Available skills and how to use them
     * 6. Memory Context - Relevant memories from episodic/key-value memory
     *
     * @param  string  $agentDir  The agent's working directory
     * @param  array  $options  Optional parameters:
     *                          - teammates: array of teammate info ['id' => string, 'name' => string, 'model' => string][]
     *                          - custom_prompt: string|null Custom prompt from database
     *                          - agent_id: string The agent's ID (for self-reference)
     *                          - agent_name: string The agent's display name
     *                          - agent_model: string The agent's model
     *                          - sender_id: string|null The sender's ID (for memory context)
     *                          - channel: ChannelEnum|null The channel (for memory context)
     *                          - message: string|null The user message (for memory search)
     * @return string The compiled system prompt
     */
    public function buildSystemPrompt(string $agentDir, array $options = []): string
    {
        $sections = [];

        // 1. Core instructions from AGENTS.md
        $agentsMd = $this->loadFile($agentDir.'/AGENTS.md');
        if ($agentsMd !== null) {
            // Inject teammate info into AGENTS.md markers if provided
            if (! empty($options['teammates']) || ! empty($options['agent_id'])) {
                $agentsMd = $this->teammateInjector->inject(
                    $agentsMd,
                    $options['agent_id'] ?? null,
                    $options['agent_name'] ?? null,
                    $options['agent_model'] ?? null,
                    $options['teammates'] ?? []
                );
            }
            $sections['instructions'] = $agentsMd;
        }

        // 2. Identity/personality from SOUL.md
        $soulMd = $this->loadFile($agentDir.'/.laraclaw/SOUL.md');
        if ($soulMd !== null) {
            $sections['identity'] = "# Your Identity\n\n".$soulMd;
        }

        // 3. Custom prompt from database (agent-specific instructions)
        if (! empty($options['custom_prompt'])) {
            $sections['custom'] = "# Additional Instructions\n\n".$options['custom_prompt'];
        }

        // 4. Available skills
        if ($this->skillService !== null) {
            $skillsSection = $this->skillPromptBuilder->build();
            if ($skillsSection !== null) {
                $sections['skills'] = $skillsSection;
            }
        }

        // 5. Memory context (episodic + key-value memory)
        if ($this->memoryService !== null && ! empty($options['sender_id']) && ! empty($options['channel'])) {
            $memoryBuilder = new MemoryContextBuilder($this->memoryService);
            $memoryContext = $memoryBuilder->build(
                $options['sender_id'],
                $options['channel'],
                $options['message'] ?? null
            );
            if ($memoryContext !== null) {
                $sections['memory'] = $memoryContext;
            }
        }

        return $this->combineSections($sections);
    }

    /**
     * Build the skills section for the system prompt.
     * Delegates to SkillPromptBuilder.
     *
     * @see SkillPromptBuilder::build()
     */
    protected function buildSkillsSection(): ?string
    {
        return $this->skillPromptBuilder->build();
    }

    /**
     * Load a file's contents if it exists.
     *
     * @param  string  $path  The file path
     * @return string|null The file contents or null if not found
     */
    public function loadFile(string $path): ?string
    {
        if (! File::exists($path)) {
            return null;
        }

        $content = File::get($path);

        return $content !== '' ? $content : null;
    }

    /**
     * Inject teammate information into AGENTS.md between markers.
     *
     * @param  string  $content  The AGENTS.md content
     * @param  string|null  $agentId  The current agent's ID
     * @param  string|null  $agentName  The current agent's display name
     * @param  string|null  $agentModel  The current agent's model
     * @param  array  $teammates  List of teammates
     * @return string The modified content
     */
    /**
     * Inject teammate information into AGENTS.md between markers.
     * Delegates to TeammatePromptInjector.
     *
     * @see TeammatePromptInjector::inject()
     */
    protected function injectTeammateInfo(
        string $content,
        ?string $agentId,
        ?string $agentName,
        ?string $agentModel,
        array $teammates
    ): string {
        return $this->teammateInjector->inject($content, $agentId, $agentName, $agentModel, $teammates);
    }

    /**
     * Combine sections into a single prompt with visual separators.
     *
     * @param  array  $sections  Associative array of section name => content
     * @return string The combined prompt
     */
    protected function combineSections(array $sections): string
    {
        $sections = array_filter($sections); // Remove empty sections

        if (empty($sections)) {
            return '';
        }

        $separator = "\n\n".str_repeat('─', 40)."\n\n";

        return implode($separator, $sections);
    }

    /**
     * Extract teammate information from teams for a given agent.
     *
     * @param  string  $agentId  The agent ID to find teammates for
     * @param  AgentCollection  $agents  All available agents
     * @param  TeamCollection  $teams  All available teams
     * @return array List of teammates with id, name, and model
     */
    /**
     * Extract teammate information from teams for a given agent.
     * Delegates to TeammatePromptInjector.
     *
     * @see TeammatePromptInjector::extractTeammates()
     */
    public function extractTeammates(string $agentId, AgentCollection $agents, TeamCollection $teams): array
    {
        return $this->teammateInjector->extractTeammates($agentId, $agents, $teams);
    }

    /**
     * Clear the compiled prompt cache for an agent.
     * Call this when AGENTS.md or SOUL.md changes.
     *
     * @param  string  $agentDir  The agent's working directory
     */
    public function clearCache(string $agentDir): void
    {
        $cacheKey = $this->getCacheKey($agentDir);
        \Illuminate\Support\Facades\Cache::forget($cacheKey);
    }

    /**
     * Build system prompt with caching for performance.
     *
     * @param  string  $agentDir  The agent's working directory
     * @param  array  $options  Options for prompt building
     * @param  int  $ttl  Cache time-to-live in seconds (default: 1 hour)
     * @return string The compiled system prompt
     */
    public function buildSystemPromptCached(string $agentDir, array $options = [], int $ttl = 3600): string
    {
        $cacheKey = $this->getCacheKey($agentDir, $options);

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, $ttl, function () use ($agentDir, $options) {
            return $this->buildSystemPrompt($agentDir, $options);
        });
    }

    /**
     * Generate a cache key for an agent's prompt.
     *
     * @param  string  $agentDir  The agent's working directory
     * @param  array  $options  Options that affect the prompt
     * @return string The cache key
     */
    protected function getCacheKey(string $agentDir, array $options = []): string
    {
        // Include relevant options in cache key
        $keyParts = [
            'laraclaw.system_prompt',
            md5($agentDir),
        ];

        // Include teammate hash if present (teammates can change)
        if (! empty($options['teammates'])) {
            $keyParts[] = md5(serialize($options['teammates']));
        }

        // Include custom prompt hash if present
        if (! empty($options['custom_prompt'])) {
            $keyParts[] = md5($options['custom_prompt']);
        }

        return implode('.', $keyParts);
    }
}

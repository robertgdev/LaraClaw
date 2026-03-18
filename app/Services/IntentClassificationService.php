<?php

namespace App\Services;

use App\DTOs\AgentSuggestionResultDTO;
use App\DTOs\ExtractedEntitiesDTO;
use App\DTOs\IntentClassificationDTO;
use App\DTOs\SkillMatchStatisticsDTO;
use App\Services\Intent\AgentSuggestionService;
use App\Services\Intent\EntityExtractor;
use App\Services\Intent\IntentCacheManager;
use App\Services\Intent\LlmIntentClassifier;
use App\TypedCollections\AgentCollection;
use Illuminate\Support\Str;

class IntentClassificationService
{
    protected SettingsService $settings;

    protected ?SkillSearchService $skillService = null;

    protected IntentCacheManager $cacheManager;

    protected LlmIntentClassifier $llmClassifier;

    protected EntityExtractor $entityExtractor;

    protected ?AgentSuggestionService $agentSuggestionService = null;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
        $this->cacheManager = new IntentCacheManager;
        $this->llmClassifier = new LlmIntentClassifier($settings);
        $this->entityExtractor = new EntityExtractor;
    }

    /**
     * Set the skill service dependency (to avoid circular dependency).
     */
    public function setSkillService(SkillSearchService $skillService): void
    {
        $this->skillService = $skillService;
    }

    // ==========================================
    // Main Classification Methods
    // ==========================================

    /**
     * Classify the intent of a user message with skill matching cache.
     */
    public function classify(string $message): IntentClassificationDTO
    {
        $keywords = $this->extractKeywords($message);

        // Check cache (both tiers)
        $cached = $this->cacheManager->find($message, $keywords);
        if ($cached) {
            return $cached;
        }

        // Quick pattern matching for obvious cases
        $quickResult = $this->quickClassify($message);
        if ($quickResult->confidence >= 0.9) {
            $this->cacheManager->storeInMemory($quickResult, $message);

            return $quickResult;
        }

        // Use LLM for more nuanced classification with skill matching
        $result = $this->llmClassifier->classify($message, $keywords, $this->skillService);

        // If LLM returned a fallback, try to use quickClassify as fallback instead
        if ($result->method === 'fallback') {
            $result = $this->quickClassify($message, true);
        }

        $this->cacheManager->store($result, $keywords, $message);

        return $result;
    }

    /**
     * Classify intent and match skills in a single LLM call.
     */
    public function classifyWithSkillMatch(string $message): IntentClassificationDTO
    {
        $keywords = $this->extractKeywords($message);

        // Check cache first
        $cached = $this->cacheManager->find($message, $keywords);
        if ($cached) {
            return $cached;
        }

        // Use LLM for classification
        $result = $this->llmClassifier->classify($message, $keywords, $this->skillService);

        $this->cacheManager->store($result, $keywords, $message);

        return $result;
    }

    // ==========================================
    // Quick Pattern Classification
    // ==========================================

    /**
     * Quick pattern-based classification for obvious cases.
     */
    protected function quickClassify(string $message, bool $isFallback = false): IntentClassificationDTO
    {
        $lowerMessage = strtolower($message);
        $scores = collect();

        $intentCategories = config('laraclaw.intent_classification.intent_categories', []);
        foreach ($intentCategories as $category => $config) {
            $score = 0;
            foreach ($config['examples'] as $pattern) {
                if (Str::of($lowerMessage)->contains($pattern)) {
                    $score += 0.3;
                }
            }
            $scores->put($category, min($score, 1.0));
        }

        // Find best match
        $scores = $scores->sortDesc();
        $firstKey = $scores->keys()->first();

        return new IntentClassificationDTO(
            intent: $firstKey,
            confidence: $scores[$firstKey] ?? 0,
            method: $isFallback ? 'fallback' : 'pattern',
            allScores: $scores->all(),
        );
    }

    // ==========================================
    // Delegated Methods
    // ==========================================

    /**
     * Extract entities from the message (locations, dates, etc.).
     *
     * Delegates to EntityExtractor.
     */
    public function extractEntities(string $message): ExtractedEntitiesDTO
    {
        return $this->entityExtractor->extract($message);
    }

    /**
     * Get suggested agent based on intent and entities.
     *
     * Delegates to AgentSuggestionService.
     *
     * @param  string  $message  The user message
     * @param  AgentCollection  $agents  Collection of Agent models (keyed by agent_id)
     */
    public function suggestAgent(string $message, AgentCollection $agents): AgentSuggestionResultDTO
    {
        if (! $this->agentSuggestionService) {
            $this->agentSuggestionService = new AgentSuggestionService($this, $this->entityExtractor);
        }

        return $this->agentSuggestionService->suggest($message, $agents);
    }

    // ==========================================
    // Keyword Extraction
    // ==========================================

    /**
     * Extract keywords from a message.
     *
     * Delegates to the shared KeywordExtractor utility.
     *
     * @return array<int, string>
     */
    public function extractKeywords(string $message, int $max = 20): array
    {
        return KeywordExtractor::extract($message, $max);
    }

    // ==========================================
    // Cache Management (Delegated)
    // ==========================================

    /**
     * Get cache statistics.
     */
    public function getCacheStatistics(): SkillMatchStatisticsDTO
    {
        return $this->cacheManager->getStatistics();
    }

    /**
     * Clear the skill match cache.
     */
    public function clearCache(): void
    {
        $this->cacheManager->clearAll();
    }

    // ==========================================
    // Session Intent Detection (Delegated to SessionService)
    // ==========================================

    /**
     * Check if a message is a session-related command.
     *
     * @deprecated Use SessionService::detectSessionIntent() instead.
     *             This method is retained for backward compatibility only.
     */
    public function detectSessionIntent(string $message): ?string
    {
        return app(SessionService::class)->detectSessionIntent($message);
    }

    /**
     * Check if an intent is a session-related intent.
     *
     * @deprecated Use SessionService::SESSION_INTENTS constant instead.
     *             This method is retained for backward compatibility only.
     */
    public function isSessionIntent(string $intent): bool
    {
        return in_array($intent, SessionService::SESSION_INTENTS);
    }

    // ==========================================
    // Accessor Methods for Extracted Components
    // ==========================================

    /**
     * Get the LLM intent classifier.
     */
    public function getLlmClassifier(): LlmIntentClassifier
    {
        return $this->llmClassifier;
    }

    /**
     * Get the entity extractor.
     */
    public function getEntityExtractor(): EntityExtractor
    {
        return $this->entityExtractor;
    }

    /**
     * Get the cache manager.
     */
    public function getCacheManager(): IntentCacheManager
    {
        return $this->cacheManager;
    }
}

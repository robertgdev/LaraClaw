<?php

namespace App\Services;

use App\DTOs\CacheStatsDTO;
use App\DTOs\SkillClassificationResultDTO;
use App\Logging\MultiLogger;
use App\Models\Skill;
use App\Models\SkillMatch;
use App\Services\Skills\ApiErrorClassifier;
use App\Services\Skills\ClassificationMappingRepository;
use App\Services\Skills\ClassificationPromptBuilder;
use App\Services\Skills\ClassificationResponseParser;
use App\TypedCollections\IntentMappingDTOCollection;
use Prism\Prism\Facades\Prism;

/**
 * Service for pre-classifying skills and populating the intent cache.
 *
 * This service orchestrates the classification pipeline by delegating to:
 * - {@see ClassificationPromptBuilder} for LLM prompt construction
 * - {@see ClassificationResponseParser} for parsing LLM JSON responses
 * - {@see ClassificationMappingRepository} for database storage
 * - {@see ApiErrorClassifier} for user-friendly error messages
 *
 * ## Checksum-Based Change Detection
 *
 * The service uses checksums to detect skill changes and avoid re-classifying
 * unchanged skills. This saves LLM tokens and reduces API costs.
 */
class SkillClassificationService
{
    protected SettingsService $settings;

    protected SkillSearchService $skillService;

    protected ClassificationPromptBuilder $promptBuilder;

    protected ClassificationResponseParser $responseParser;

    protected ClassificationMappingRepository $mappingRepository;

    protected ApiErrorClassifier $errorClassifier;

    /**
     * Fast/cheap models for classification tasks per provider.
     *
     * @var array<string, string>
     */
    protected array $fastModels = [
        'groq' => 'llama-3.3-70b-versatile',
        'deepseek' => 'deepseek-chat',
        'openai' => 'gpt-4o-mini',
        'anthropic' => 'claude-3-5-haiku-20241022',
        'gemini' => 'gemini-2.0-flash',
        'mistral' => 'mistral-small-latest',
        'xai' => 'grok-beta',
        'ollama' => 'llama3.2',
    ];

    public function __construct(
        SettingsService $settings,
        SkillSearchService $skillService,
        ?ClassificationPromptBuilder $promptBuilder = null,
        ?ClassificationResponseParser $responseParser = null,
        ?ClassificationMappingRepository $mappingRepository = null,
        ?ApiErrorClassifier $errorClassifier = null
    ) {
        $this->settings = $settings;
        $this->skillService = $skillService;
        $this->promptBuilder = $promptBuilder ?? new ClassificationPromptBuilder;
        $this->responseParser = $responseParser ?? new ClassificationResponseParser;
        $this->mappingRepository = $mappingRepository ?? new ClassificationMappingRepository;
        $this->errorClassifier = $errorClassifier ?? new ApiErrorClassifier;
    }

    /**
     * Classify all skills and populate the cache.
     * Uses checksum-based change detection to skip unchanged skills.
     *
     * @param  bool  $clearExisting  Whether to clear existing mappings before classification
     * @param  callable|null  $progressCallback  Callback called after each skill:
     *                                           fn(string $skillName, int $mappingsCount, int $total, int $current, string $status) => void
     */
    public function classifyAllSkills(bool $clearExisting = false, ?callable $progressCallback = null): SkillClassificationResultDTO
    {
        $errors = [];
        $allMappings = new IntentMappingDTOCollection();
        $skillsDetails = [];
        $skillsProcessed = 0;
        $skillsSkipped = 0;

        // 1. Optionally clear existing mappings
        if ($clearExisting) {
            SkillMatch::clearAll();
            // Reset all skill classification statuses
            Skill::query()->update(['classification_status' => Skill::STATUS_PENDING]);
            MultiLogger::info('Cleared existing skill match cache and reset classification statuses');
        }

        // 2. Index skills from filesystem and sync to database
        $indexedSkills = $this->skillService->indexSkills();
        $syncStats = Skill::syncFromIndex($indexedSkills);

        MultiLogger::info('Synced skills from filesystem', $syncStats->toArray());

        if (empty($indexedSkills)) {
            MultiLogger::warning('No skills found to classify');

            return new SkillClassificationResultDTO(
                skillsProcessed: 0,
                skillsSkipped: 0,
                mappingsGenerated: 0,
                mappingsStored: 0,
                errors: ['No skills found'],
            );
        }

        // 3. Get skills that need classification
        $skillsToClassify = Skill::active()->needsClassification()->get();
        $totalSkills = $skillsToClassify->count();

        MultiLogger::info('Starting skill classification', [
            'total_skills' => Skill::active()->count(),
            'skills_needing_classification' => $totalSkills,
        ]);

        // 4. Process each skill
        $currentSkill = 0;
        foreach ($skillsToClassify as $skill) {
            $currentSkill++;
            MultiLogger::debug('Processing skill', ['skill' => $skill->name]);

            $mappingsCount = 0;
            $status = 'classified';

            try {
                // Build prompt for this single skill
                $prompt = $this->promptBuilder->buildSingleSkillPrompt($skill->name, [
                    'description' => $skill->description,
                    'keywords' => $skill->keywords ?? [],
                ]);

                // Send to LLM
                $provider = $this->settings->getDefaultProvider();
                $model = $this->getClassificationModel($provider);
                $response = $this->sendClassificationRequest($prompt);

                // Parse response
                $mappings = $this->responseParser->parse($response, $skill->id);

                if ($mappings->isNotEmpty()) {
                    $mappingsCount = $mappings->count();
                    $allMappings = $allMappings->merge($mappings);
                    $skillsDetails[$skill->name] = $this->promptBuilder->buildSkillDetails($mappings);
                }

                // Mark skill as classified
                $skill->markClassified($mappingsCount, $provider, $model);
                $skillsProcessed++;

            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
                $errorType = $this->errorClassifier->classify($errorMsg);

                MultiLogger::error('Failed to classify skill', [
                    'skill' => $skill->name,
                    'error' => $errorMsg,
                    'error_type' => $errorType,
                    'trace' => $e->getTrace(),
                ]);

                $skill->markFailed($errorType);
                $errors[] = "{$skill->name}: {$errorType}";
                $status = 'failed';
            }

            // Call progress callback if provided
            if ($progressCallback !== null) {
                $progressCallback($skill->name, $mappingsCount, $totalSkills, $currentSkill, $status);
            }
        }

        // 5. Count skipped skills (already classified with same checksum)
        // FIXME: only skip if it actually has skills
        $skillsSkipped = Skill::active()
            ->where('classification_status', Skill::STATUS_CLASSIFIED)
            ->count();

        // 6. Store all mappings
        $stored = $this->mappingRepository->storeMappings($allMappings);

        MultiLogger::info('Skill classification complete', [
            'skills_processed' => $skillsProcessed,
            'skills_skipped' => $skillsSkipped,
            'mappings_generated' => count($allMappings),
            'mappings_stored' => $stored,
        ]);

        return new SkillClassificationResultDTO(
            skillsProcessed: $skillsProcessed,
            skillsSkipped: $skillsSkipped,
            mappingsGenerated: count($allMappings),
            mappingsStored: $stored,
            skillsDetails: $skillsDetails,
            errors: $errors,
        );
    }

    /**
     * Send the classification request to the LLM.
     *
     * @param  string  $prompt  The prompt to send
     * @return string The LLM response text
     *
     * @throws \Exception If the LLM request fails
     */
    protected function sendClassificationRequest(string $prompt): string
    {
        $provider = $this->settings->getDefaultProvider();
        $model = $this->getClassificationModel($provider);
        $providerEnum = ProviderMapper::resolve($provider);

        MultiLogger::debug('Sending skill classification request', [
            'provider' => $provider,
            'model' => $model,
        ]);

        $response = Prism::text()
            ->using($providerEnum, $model)
            ->withPrompt($prompt)
            ->asText();

        return $response->text;
    }

    /**
     * Get a fast/cheap model for classification tasks.
     *
     * @param  string  $provider  The provider name
     * @return string The model to use
     */
    public function getClassificationModel(string $provider): string
    {
        // Use fast model if available, otherwise use default
        if (isset($this->fastModels[$provider])) {
            return $this->fastModels[$provider];
        }

        return $this->settings->getDefaultModel($provider);
    }

    /**
     * Parse the LLM classification response.
     * Delegates to ClassificationResponseParser.
     *
     * @param  string  $response  The raw LLM response
     * @param  int|null  $skillId  The skill ID to associate with mappings
     * @return IntentMappingDTOCollection Collection of parsed mappings
     */
    public function parseClassificationResponse(string $response, ?int $skillId = null): IntentMappingDTOCollection
    {
        return $this->responseParser->parse($response, $skillId);
    }

    /**
     * Store the parsed mappings in the database.
     * Delegates to ClassificationMappingRepository.
     *
     * @param  IntentMappingDTOCollection  $mappings  Collection of parsed mappings
     * @return int Number of mappings stored
     */
    public function storeMappings(IntentMappingDTOCollection $mappings): int
    {
        return $this->mappingRepository->storeMappings($mappings);
    }

    /**
     * Get statistics about the current skill match cache.
     */
    public function getCacheStatistics(): CacheStatsDTO
    {
        $stats = SkillMatch::getStatistics();
        $skillStats = Skill::getClassificationStats();

        return new CacheStatsDTO(
            totalEntries: $stats->totalEntries,
            totalHits: $stats->totalHits,
            skillsCovered: count($stats->topSkills),
            skillsPending: $skillStats->pending,
            skillsClassified: $skillStats->classified,
            skillsFailed: $skillStats->failed,
        );
    }

    /**
     * Set the number of intents to generate per skill.
     */
    public function setIntentsPerSkill(int $count): self
    {
        $this->promptBuilder->setIntentsPerSkill($count);

        return $this;
    }

    /**
     * Get the prompt builder instance.
     */
    public function getPromptBuilder(): ClassificationPromptBuilder
    {
        return $this->promptBuilder;
    }

    /**
     * Get the response parser instance.
     */
    public function getResponseParser(): ClassificationResponseParser
    {
        return $this->responseParser;
    }

    /**
     * Get the mapping repository instance.
     */
    public function getMappingRepository(): ClassificationMappingRepository
    {
        return $this->mappingRepository;
    }

    /**
     * Get the error classifier instance.
     */
    public function getErrorClassifier(): ApiErrorClassifier
    {
        return $this->errorClassifier;
    }
}

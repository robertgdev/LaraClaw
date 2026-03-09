<?php

declare(strict_types=1);

namespace App\Services\Intent;

use App\DTOs\IntentClassificationDTO;
use App\Logging\MultiLogger;
use App\Services\ProviderMapper;
use App\Services\SettingsService;
use App\Services\SkillSearchService;
use Prism\Prism\Facades\Prism;

use function Safe\json_decode;
use function Safe\preg_match;

/**
 * LlmIntentClassifier - Uses an LLM for nuanced intent classification.
 *
 * Encapsulates prompt building, model selection, LLM invocation,
 * and response parsing for intent classification with skill matching.
 */
class LlmIntentClassifier
{
    protected SettingsService $settings;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Classify a message using the LLM with skill matching.
     *
     * @param  string  $message  The user message
     * @param  array<int,string>  $keywords  Extracted keywords
     * @param  SkillSearchService|null  $skillService  Optional skill service
     */
    public function classify(string $message, array $keywords, ?SkillSearchService $skillService = null): IntentClassificationDTO
    {
        $availableSkills = [];
        if ($skillService) {
            $skills = $skillService->getAllSkills();
            foreach ($skills as $name => $skill) {
                $availableSkills[$name] = [
                    'name' => $name,
                    'description' => substr($skill['description'], 0, 200),
                ];
            }
        }

        $skillList = ! empty($availableSkills)
            ? implode(', ', array_keys($availableSkills))
            : 'imagegen, schedule, agent-browser, send-user-message, skill-creator';

        $categories = array_keys(config('laraclaw.intent_classification.intent_categories', []));
        $categoryList = implode(', ', $categories);

        $prompt = $this->buildPrompt($message, $categoryList, $skillList, $keywords);

        try {
            $provider = $this->settings->getDefaultProvider();
            $model = $this->settings->getDefaultModel($provider);
            $providerEnum = ProviderMapper::resolve($provider);

            $classificationModel = $this->getClassificationModel($provider, $model);

            $response = Prism::text()
                ->using($providerEnum, $classificationModel)
                ->withPrompt($prompt)
                ->asText();

            $text = $response->text;

            $result = $this->parseResponse($text, $message, $keywords);

            MultiLogger::debug('LLM intent classification with skills', [
                'message' => substr($message, 0, 100),
                'result' => $result,
            ]);

            return $result;

        } catch (\Exception $e) {
            MultiLogger::error('Intent classification error', [
                'message' => $message,
                'error' => $e->getMessage(),
            ]);

            return new IntentClassificationDTO(
                intent: 'unknown',
                confidence: 0.3,
                method: 'fallback',
            );
        }
    }

    /**
     * Build the classification prompt for the LLM.
     *
     * @param  array<string>  $keywords
     */
    public function buildPrompt(string $message, string $categoryList, string $skillList, array $keywords): string
    {
        $keywordsStr = implode(', ', array_slice($keywords, 0, 10));

        return <<<PROMPT
You are an intent classifier and skill matcher. Analyze the user message and respond with a JSON object.

## Task
1. Classify the message into one intent category
2. Match the message to the most relevant skill (if any)
3. Extract any entities (locations, dates, etc.)
4. Suggest an agent if the message clearly matches a specific agent type

## Available Intent Categories
{$categoryList}

## Available Skills
{$skillList}

## User Message
"{$message}"

## Extracted Keywords
{$keywordsStr}

## Response Format
Respond with ONLY a JSON object (no markdown, no explanation):
{
    "intent": "category_name",
    "confidence": 0.95,
    "matched_skill": "skill_name or null",
    "skill_confidence": 0.90,
    "entities": {
        "locations": [],
        "dates": [],
        "people": [],
        "topics": []
    },
    "suggested_agent": "agent_id or null",
    "reasoning": "brief explanation"
}

## Rules
- confidence must be between 0.0 and 1.0
- matched_skill should be null if no skill is relevant
- skill_confidence should be null if no skill matched
- suggested_agent should be null if no specific agent is indicated
- Only include entities that are actually present in the message
PROMPT;
    }

    /**
     * Get a fast/cheap model for classification tasks.
     */
    public function getClassificationModel(string $provider, string $defaultModel): string
    {
        $fastModels = [
            'groq' => 'llama-3.3-70b-versatile',
            'deepseek' => 'deepseek-chat',
            'openai' => 'gpt-4o-mini',
            'anthropic' => 'claude-3-5-haiku-20241022',
            'gemini' => 'gemini-2.0-flash',
        ];

        return $fastModels[$provider] ?? $defaultModel;
    }

    /**
     * Parse LLM classification response with skill matching.
     *
     * @param  array<string>  $keywords
     */
    public function parseResponse(string $text, string $originalMessage, array $keywords): IntentClassificationDTO
    {
        if (preg_match('/\{[\s\S]*\}/s', $text, $match)) {
            // FIXME: try/catch
            $decoded = json_decode($match[0], true);
            if ($decoded && isset($decoded['intent'])) {
                $intent = $decoded['intent'];
                $confidence = $decoded['confidence'] ?? 0.5;

                $intentCategories = config('laraclaw.intent_classification.intent_categories', []);
                if (! isset($intentCategories[$intent])) {
                    $intent = 'unknown';
                    $confidence = 0.3;
                }

                return new IntentClassificationDTO(
                    intent: $intent,
                    confidence: (float) $confidence,
                    matchedSkill: $decoded['matched_skill'] ?? null,
                    skillConfidence: $decoded['skill_confidence'] ?? null,
                    entities: $decoded['entities'] ?? [],
                    suggestedAgent: $decoded['suggested_agent'] ?? null,
                    reasoning: $decoded['reasoning'] ?? null,
                    method: 'llm',
                    fromCache: false,
                    keywords: $keywords,
                );
            }
        }

        return new IntentClassificationDTO(
            intent: 'unknown',
            confidence: 0.3,
            matchedSkill: null,
            skillConfidence: null,
            entities: [],
            suggestedAgent: null,
            reasoning: 'Failed to parse LLM response',
            method: 'fallback',
            fromCache: false,
            keywords: $keywords,
        );
    }
}

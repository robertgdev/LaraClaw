<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\TypedCollections\IntentMappingDTOCollection;
use Illuminate\Support\Str;

/**
 * Builds LLM prompts for skill classification.
 *
 * Generates structured prompts that ask the LLM to produce sample user intents
 * for a given skill, which are used to populate the intent cache.
 */
class ClassificationPromptBuilder
{
    /**
     * Number of sample intents to generate per skill.
     */
    protected int $intentsPerSkill;

    public function __construct(?int $intentsPerSkill = null)
    {
        $this->intentsPerSkill = $intentsPerSkill ?? config('laraclaw.skill_preclassification.intents_per_skill', 5);
    }

    /**
     * Build a prompt for classifying a single skill.
     *
     * @param  string  $skillName  The skill name
     * @param  array{description?: string, keywords?: array<int, string>}  $skill  The skill data with 'description' and optional 'keywords'
     * @return string The prompt
     */
    public function buildSingleSkillPrompt(string $skillName, array $skill): string
    {
        // Truncate description to avoid large prompts
        $description = Str::limit($skill['description'] ?? 'No description available', 200);
        $keywords = implode(', ', array_slice($skill['keywords'] ?? [], 0, 5));
        $intentsPerSkill = $this->intentsPerSkill;

        return <<<PROMPT
You are a skill classification assistant. Generate sample user intents for the following skill.

## Skill: {$skillName}
Description: {$description}
Keywords: {$keywords}

## Task
Generate exactly {$intentsPerSkill} diverse sample user messages/intents that would trigger this skill.

## Response Format
Respond with ONLY a JSON array (no markdown, no explanation):
[
  {
    "sample_intent": "a sample user message that would trigger this skill",
    "keywords": ["keyword1", "keyword2", "keyword3"],
    "confidence": 0.95,
    "category": "intent_category"
  }
]

## Rules
- sample_intent should be a realistic user message (natural language)
- keywords should be extracted from the sample_intent (3-5 most relevant words)
- confidence should be between 0.7 and 1.0
- category should be one of: question, command, research, coding, creative, scheduling, automation, communication
- Make intents diverse - cover different use cases and phrasings
PROMPT;
    }

    /**
     * Build details summary for a single skill from its mappings.
     *
     * @param  IntentMappingDTOCollection  $mappings  The mappings for this skill
     * @return array{intents: array<int, string>, keywords: array<int, string>}
     */
    public function buildSkillDetails(IntentMappingDTOCollection $mappings): array
    {
        $intents = [];
        $keywords = [];

        foreach ($mappings as $mapping) {
            $intents[] = $mapping->sampleIntent;
            $keywords = array_unique(array_merge($keywords, $mapping->keywords ?? []));
        }

        return [
            'intents' => $intents,
            'keywords' => $keywords,
        ];
    }

    /**
     * Set the number of intents to generate per skill.
     */
    public function setIntentsPerSkill(int $count): self
    {
        $this->intentsPerSkill = $count;

        return $this;
    }

    /**
     * Get the number of intents to generate per skill.
     */
    public function getIntentsPerSkill(): int
    {
        return $this->intentsPerSkill;
    }
}

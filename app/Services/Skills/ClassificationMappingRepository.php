<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\Logging\MultiLogger;
use App\Models\SkillMatch;

/**
 * Repository for storing and querying skill classification mappings.
 *
 * Handles the database persistence layer for intent→skill mappings,
 * including per-skill clearing of stale entries and bulk storage.
 */
class ClassificationMappingRepository
{
    /**
     * Store parsed mappings in the database.
     * Clears existing pre-classification mappings for each skill before storing new ones.
     *
     * @param  array  $mappings  Array of parsed mappings
     * @return int Number of mappings stored
     */
    public function storeMappings(array $mappings): int
    {
        $stored = 0;

        // Group mappings by skill_id
        $bySkill = [];
        foreach ($mappings as $mapping) {
            if (empty($mapping['skill_id'])) {
                continue;
            }
            $bySkill[$mapping['skill_id']][] = $mapping;
        }

        // Clear existing mappings for each skill and store new ones
        foreach ($bySkill as $skillId => $skillMappings) {
            // Delete existing pre-classification entries for this skill
            SkillMatch::forSkill($skillId)
                ->whereJsonContains('metadata->source', 'preclassification')
                ->delete();

            // Store new mappings
            foreach ($skillMappings as $mapping) {
                try {
                    SkillMatch::storeMatch(
                        keywords: $mapping['keywords'],
                        skillId: $mapping['skill_id'],
                        confidence: $mapping['confidence'],
                        category: $mapping['category'],
                        sampleMessage: $mapping['sample_intent'],
                        metadata: [
                            'source' => 'preclassification',
                            'generated_at' => now()->toIso8601String(),
                        ]
                    );
                    $stored++;
                } catch (\Exception $e) {
                    MultiLogger::warning('Failed to store skill mapping', [
                        'skill_id' => $mapping['skill_id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $stored;
    }
}

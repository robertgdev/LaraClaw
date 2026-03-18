<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\Logging\MultiLogger;
use App\Models\SkillMatch;
use App\TypedCollections\IntentMappingDTOCollection;

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
     */
    public function storeMappings(IntentMappingDTOCollection $mappings): int
    {
        $stored = 0;

        // Group mappings by skill_id
        $bySkill = [];
        foreach ($mappings as $mapping) {
            if (! $mapping->skillId) {
                continue;
            }
            $bySkill[$mapping->skillId][] = $mapping;
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
                    $metaData = [
                        'source' => 'preclassification', // FIXME: convert to enum
                        'generated_at' => now()->toIso8601String(),
                    ];
                    SkillMatch::storeMatch($mapping, metadata: $metaData);
                    $stored++;
                } catch (\Exception $e) {
                    MultiLogger::warning('Failed to store skill mapping', [
                        'skill_id' => $mapping->skillId ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $stored;
    }
}

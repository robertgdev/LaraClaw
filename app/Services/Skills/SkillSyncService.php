<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\DTOs\SkillSyncResultDTO;
use App\Logging\MultiLogger;
use App\Models\Skill;

/**
 * Syncs skills from the filesystem index to the database.
 *
 * Creates new skills, updates existing ones, and marks removed skills as inactive.
 * Uses checksum-based change detection to avoid re-classifying unchanged skills.
 */
class SkillSyncService
{
    public function __construct(
        protected SkillChecksumCalculator $checksumCalculator
    ) {}

    /**
     * Sync skills from indexed data.
     * Creates new skills, updates existing ones, and marks removed skills as inactive.
     *
     * @param  array<string, array<string, mixed>>  $indexedSkills  Skills from SkillSearchService::indexSkills()
     */
    public function syncFromIndex(array $indexedSkills): SkillSyncResultDTO
    {
        $created = 0;
        $updated = 0;

        $seenNames = [];
        foreach ($indexedSkills as $skillName => $skillData) {
            $seenNames[] = $skillName;
            $checksum = $this->checksumCalculator->calculate($skillData['directory']);

            // Base attributes
            $attributes = [
                'dir_name' => $skillData['dir_name'],
                'path' => $skillData['path'],
                'description' => $skillData['description'],
                'license' => $skillData['license'] ?? null,
                'keywords' => $skillData['keywords'] ?? [],
                'checksum' => $checksum,
                'has_scripts' => $skillData['has_scripts'] ?? false,
                'has_references' => $skillData['has_references'] ?? false,
                'has_assets' => $skillData['has_assets'] ?? false,
                'is_active' => true,
            ];

            // Check existing skill before updating to preserve classification state
            $existing = Skill::where('name', $skillName)->first();
            $isNew = $existing === null;
            $checksumChanged = $existing !== null && $existing->checksum !== $checksum;

            // Only reset classification_status for new skills or changed checksums
            if ($isNew || $checksumChanged) {
                $attributes['classification_status'] = Skill::STATUS_PENDING;
            }

            $skill = Skill::updateOrCreate(
                ['name' => $skillName],
                $attributes
            );

            // Stats
            if ($skill->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        // Deactivate skills that no longer exist in the index
        $deactivated = Skill::whereNotIn('name', $seenNames)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        MultiLogger::info('Synced skills from index', [
            'created' => $created,
            'updated' => $updated,
            'deactivated' => $deactivated,
        ]);

        return new SkillSyncResultDTO(
            created: $created,
            updated: $updated,
            deactivated: $deactivated,
        );
    }
}

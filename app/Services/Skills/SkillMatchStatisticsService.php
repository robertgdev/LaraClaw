<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\DTOs\SkillMatchStatisticsDTO;
use App\Models\Skill;
use App\Models\SkillMatch;

/**
 * Provides aggregated statistics about the skill match cache.
 */
class SkillMatchStatisticsService
{
    /**
     * Get comprehensive statistics about the cache.
     */
    public function getStatistics(): SkillMatchStatisticsDTO
    {
        return new SkillMatchStatisticsDTO(
            totalEntries: SkillMatch::count(),
            totalHits: (int) SkillMatch::sum('hit_count'),
            avgConfidence: round((float) SkillMatch::avg('confidence_score'), 2),
            highConfidenceCount: SkillMatch::highConfidence()->count(),
            topSkills: SkillMatch::selectRaw('skill_id, COUNT(*) as count, SUM(hit_count) as hits')
                ->groupBy('skill_id')
                ->orderByDesc('count')
                ->limit(10)
                ->get()
                ->map(fn ($row) => [
                    'skill_id' => $row->skill_id,
                    'skill_name' => Skill::find($row->skill_id)?->name ?? 'unknown',
                    'count' => $row->count,
                    'hits' => $row->hits,
                ])
                ->toArray(),
            topCategories: SkillMatch::selectRaw('intent_category, COUNT(*) as count')
                ->groupBy('intent_category')
                ->orderByDesc('count')
                ->limit(10)
                ->get()
                ->toArray(),
            recentEntries: SkillMatch::recent(7)->count(),
        );
    }
}

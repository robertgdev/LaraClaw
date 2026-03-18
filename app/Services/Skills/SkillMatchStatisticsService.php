<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\DTOs\SkillMatchStatisticsDTO;
use App\DTOs\TopSkillDTO;
use App\Models\Skill;
use App\Models\SkillMatch;
use App\TypedCollections\TopSkillDTOCollection;
use Illuminate\Support\Facades\DB;

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
            topSkills: $this->getTopSkills(),
            topCategories: SkillMatch::selectRaw('intent_category, COUNT(*) as count')
                ->groupBy('intent_category')
                ->orderByDesc('count')
                ->limit(10)
                ->get()
                ->toArray(),
            recentEntries: SkillMatch::recent(7)->count(),
        );
    }

    /**
     * Get top skills with their match counts and hit totals.
     */
    private function getTopSkills(): TopSkillDTOCollection
    {
        $results = DB::table('skill_matches')
            ->selectRaw('skill_id, COUNT(*) as count, SUM(hit_count) as hits')
            ->groupBy('skill_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $topSkillsCollection = new TopSkillDTOCollection;
        foreach ($results as $row) {
            $skill = Skill::find($row->skill_id);
            $topSkillsCollection->add(
                new TopSkillDTO(
                    skillId: $row->skill_id,
                    skillName: $skill->name ?? 'unknown',
                    count: (int) $row->count,
                    hits: (int) $row->hits,
                )
            );
        }

        return $topSkillsCollection;
    }
}

<?php

use App\DTOs\SkillMatchStatisticsDTO;
use App\Models\Skill;
use App\Models\SkillMatch;
use App\Services\Skills\SkillMatchStatisticsService;

beforeEach(function () {
    SkillMatch::query()->delete();
    Skill::query()->delete();

    $this->service = new SkillMatchStatisticsService;
});

describe('SkillMatchStatisticsService', function () {
    it('returns empty statistics for empty cache', function () {
        $stats = $this->service->getStatistics();

        expect($stats)->toBeInstanceOf(SkillMatchStatisticsDTO::class)
            ->and($stats->totalEntries)->toBe(0)
            ->and($stats->totalHits)->toBe(0)
            ->and($stats->highConfidenceCount)->toBe(0);
    });

    it('returns correct statistics', function () {
        $skill1 = Skill::create([
            'name' => 'imagegen',
            'dir_name' => 'imagegen',
            'path' => '/tmp/skills/imagegen',
            'source_type' => 'core',
            'description' => 'Generate images',
            'checksum' => 'abc123',
        ]);

        $skill2 = Skill::create([
            'name' => 'schedule',
            'dir_name' => 'schedule',
            'path' => '/tmp/skills/schedule',
            'source_type' => 'core',
            'description' => 'Schedule tasks',
            'checksum' => 'def456',
        ]);

        SkillMatch::create([
            'intent_signature' => 'sig1',
            'intent_keywords' => ['test'],
            'skill_id' => $skill1->id,
            'confidence_score' => 0.95,
            'hit_count' => 10,
        ]);

        SkillMatch::create([
            'intent_signature' => 'sig2',
            'intent_keywords' => ['test'],
            'skill_id' => $skill2->id,
            'confidence_score' => 0.8,
            'hit_count' => 5,
        ]);

        $stats = $this->service->getStatistics();

        expect($stats->totalEntries)->toBe(2)
            ->and($stats->totalHits)->toBe(15)
            ->and($stats->highConfidenceCount)->toBe(2)
            ->and($stats->topSkills)->toHaveCount(2);
    });
});

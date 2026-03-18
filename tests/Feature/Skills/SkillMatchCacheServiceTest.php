<?php

use App\DTOs\IntentMappingDTO;
use App\DTOs\SkillDTO;
use App\DTOs\SkillMatchStatisticsDTO;
use App\DTOs\SkillSearchResultDTO;
use App\Models\Skill;
use App\Models\SkillMatch;
use App\Services\Skills\SkillMatchCache;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->cache = new SkillMatchCache;
    SkillMatch::query()->delete();
    Skill::query()->delete();
});

describe('SkillMatchCache', function () {
    describe('findMatch', function () {
        it('finds cached match by keywords', function () {
            $skill = Skill::create([
                'name' => 'test-skill',
                'dir_name' => 'test-skill',
                'path' => '/tmp/skills/test-skill',
                'source_type' => 'core',
                'description' => 'Test skill',
                'checksum' => 'abc123',
                'keywords' => ['test', 'skill'],
            ]);

            $mapping = new IntentMappingDTO(
                sampleIntent: 'Test query',
                keywords: ['test', 'query'],
                skillId: $skill->id,
                confidence: 0.9,
                category: 'test',
            );
            SkillMatch::storeMatch($mapping);

            $result = $this->cache->findMatch(['test', 'query']);

            expect($result)->not->toBeNull()
                ->and($result->skill->name)->toBe('test-skill');
        });

        it('returns null when no match above threshold', function () {
            $skill = Skill::create([
                'name' => 'low-conf',
                'dir_name' => 'low-conf',
                'path' => '/tmp/skills/low-conf',
                'source_type' => 'core',
                'description' => 'Low confidence skill',
                'checksum' => 'def456',
                'keywords' => ['low'],
            ]);

            $mapping = new IntentMappingDTO(
                sampleIntent: 'Unrelated',
                keywords: ['unrelated'],
                skillId: $skill->id,
                confidence: 0.3,
                category: 'test',
            );
            SkillMatch::storeMatch($mapping);

            $result = $this->cache->findMatch(['completely', 'different']);

            expect($result)->toBeNull();
        });
    });

    describe('store', function () {
        it('stores a match in the database', function () {
            $skill = Skill::create([
                'name' => 'storable-skill',
                'dir_name' => 'storable-skill',
                'path' => '/tmp/skills/storable-skill',
                'source_type' => 'core',
                'description' => 'A storable skill',
                'checksum' => 'ghi789',
                'keywords' => ['store'],
            ]);

            $skillDTO = new SkillDTO(
                name: 'storable-skill',
                dirName: 'storable-skill',
                description: 'A storable skill',
                path: '/tmp/skills/storable-skill',
                directory: '/tmp/skills/storable-skill',
                keywords: ['store'],
                hasScripts: false,
                hasReferences: false,
                hasAssets: false,
                license: null,
                sourceType: 'core',
            );

            $searchResult = new SkillSearchResultDTO(
                skill: $skillDTO,
                score: 10,
                matchedKeywords: ['store'],
            );

            $this->cache->store(
                ['store', 'test'],
                $searchResult,
                'Store a test message',
                ['intent' => 'automation']
            );

            $stored = SkillMatch::whereHas('skill', fn ($q) => $q->where('name', 'storable-skill'))->first();
            expect($stored)->not->toBeNull()
                ->and($stored->confidence_score)->toBe(0.5); // 10/20
        });

        it('skips store when keywords are empty', function () {
            $countBefore = SkillMatch::count();

            $skillDTO = new SkillDTO(
                name: 'whatever',
                dirName: 'whatever',
                description: 'Whatever',
                path: '/tmp/whatever',
                directory: '/tmp/whatever',
                keywords: [],
                hasScripts: false,
                hasReferences: false,
                hasAssets: false,
            );

            $searchResult = new SkillSearchResultDTO(
                skill: $skillDTO,
                score: 5,
                matchedKeywords: [],
            );

            $this->cache->store(
                [],
                $searchResult,
                'empty keywords'
            );

            expect(SkillMatch::count())->toBe($countBefore);
        });
    });

    describe('buildCacheHitResult', function () {
        it('builds result array and records hit', function () {
            $skill = Skill::create([
                'name' => 'cache-hit-skill',
                'dir_name' => 'cache-hit-skill',
                'path' => '/tmp/skills/cache-hit-skill',
                'source_type' => 'core',
                'description' => 'A cached skill',
                'checksum' => 'jkl012',
                'keywords' => ['cached'],
                'has_scripts' => false,
                'has_references' => false,
                'has_assets' => false,
            ]);

            $mapping = new IntentMappingDTO(
                sampleIntent: 'Cached query',
                keywords: ['cached', 'query'],
                skillId: $skill->id,
                confidence: 0.85,
                category: 'test',
            );
            $match = SkillMatch::storeMatch($mapping);

            $match->refresh();
            $initialHits = $match->hit_count;

            $result = $this->cache->buildCacheHitResult($match, ['cached', 'query']);

            expect($result)->toHaveCount(1);
            $firstResult = $result->first();
            expect($firstResult->fromCache)->toBeTrue()
                ->and($firstResult->skill->name)->toBe('cache-hit-skill')
                ->and($firstResult->score)->toBe(8); // 0.85 * 10, rounded to int

            $match->refresh();
            expect($match->hit_count)->toBeGreaterThan($initialHits);
        });
    });

    describe('getStatistics', function () {
        it('returns statistics DTO', function () {
            $stats = $this->cache->getStatistics();

            expect($stats)->toBeInstanceOf(SkillMatchStatisticsDTO::class)
                ->and($stats->totalEntries)->toBe(0);
        });
    });

    describe('clearAll', function () {
        it('removes all entries', function () {
            $skill = Skill::create([
                'name' => 'clearable',
                'dir_name' => 'clearable',
                'path' => '/tmp/skills/clearable',
                'source_type' => 'core',
                'description' => 'Clearable',
                'checksum' => 'mno345',
            ]);

            $mapping1 = new IntentMappingDTO(
                sampleIntent: 'A query',
                keywords: ['a'],
                skillId: $skill->id,
                confidence: 0.9,
                category: 'test',
            );
            $mapping2 = new IntentMappingDTO(
                sampleIntent: 'B query',
                keywords: ['b'],
                skillId: $skill->id,
                confidence: 0.8,
                category: 'test',
            );
            SkillMatch::storeMatch($mapping1);
            SkillMatch::storeMatch($mapping2);

            expect(SkillMatch::count())->toBe(2);

            $this->cache->clearAll();

            expect(SkillMatch::count())->toBe(0);
        });
    });

    describe('cleanup', function () {
        it('removes old entries with low hit counts', function () {
            $skill = Skill::create([
                'name' => 'old-skill',
                'dir_name' => 'old-skill',
                'path' => '/tmp/skills/old-skill',
                'source_type' => 'core',
                'description' => 'Old',
                'checksum' => 'pqr678',
            ]);

            \DB::table('skill_matches')->insert([
                'intent_signature' => 'old-sig',
                'intent_keywords' => json_encode(['old']),
                'skill_id' => $skill->id,
                'confidence_score' => 0.5,
                'hit_count' => 1,
                'created_at' => now()->subDays(60),
                'updated_at' => now()->subDays(60),
            ]);

            $deleted = $this->cache->cleanup(30, 2);

            expect($deleted)->toBe(1);
        });
    });
});

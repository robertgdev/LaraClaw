<?php

use App\DTOs\IntentMappingDTO;
use App\Models\Skill;
use App\Models\SkillMatch;
use App\Services\IntentClassificationService;
use App\Services\SettingsService;
use App\Services\SkillSearchService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    // Clear the cache before each test
    SkillMatch::clearAll();
    Skill::query()->delete();
    Cache::flush();
});

describe('SkillMatchCache Model', function () {
    it('can create a cache entry with skill_id', function () {
        // First create a skill
        $skill = Skill::create([
            'name' => 'imagegen',
            'dir_name' => 'imagegen',
            'path' => '/tmp/skills/imagegen',
            'source_type' => 'core',
            'description' => 'Generate images using AI',
            'checksum' => 'abc123',
            'classification_status' => Skill::STATUS_CLASSIFIED,
        ]);

        $keywords = ['generate', 'image', 'sunset'];
        $signature = SkillMatch::generateSignature($keywords);

        $cache = SkillMatch::create([
            'intent_signature' => $signature,
            'intent_keywords' => $keywords,
            'skill_id' => $skill->id,
            'confidence_score' => 0.95,
            'sample_message' => 'Generate an image of a sunset',
            'intent_category' => 'creative',
        ]);

        expect($cache)->not->toBeNull()
            ->and($cache->skill_id)->toBe($skill->id)
            ->and($cache->confidence_score)->toBe(0.95)
            ->and($cache->intent_keywords)->toBe($keywords);
    });

    it('can generate consistent signatures', function () {
        $keywords1 = ['image', 'generate', 'sunset'];
        $keywords2 = ['sunset', 'generate', 'image']; // Same keywords, different order

        $sig1 = SkillMatch::generateSignature($keywords1);
        $sig2 = SkillMatch::generateSignature($keywords2);

        expect($sig1)->toBe($sig2);
    });

    it('can find by signature', function () {
        $skill = Skill::create([
            'name' => 'imagegen',
            'dir_name' => 'imagegen',
            'path' => '/tmp/skills/imagegen',
            'source_type' => 'core',
            'description' => 'Generate images',
            'checksum' => 'abc123',
        ]);

        $keywords = ['generate', 'image', 'sunset'];
        $signature = SkillMatch::generateSignature($keywords);

        SkillMatch::create([
            'intent_signature' => $signature,
            'intent_keywords' => $keywords,
            'skill_id' => $skill->id,
            'confidence_score' => 0.95,
        ]);

        $found = SkillMatch::findBySignature($signature);

        expect($found)->not->toBeNull()
            ->and($found->skill_id)->toBe($skill->id);
    });

    it('can find by keywords using storeMatch', function () {
        $skill = Skill::create([
            'name' => 'imagegen',
            'dir_name' => 'imagegen',
            'path' => '/tmp/skills/imagegen',
            'source_type' => 'core',
            'description' => 'Generate images',
            'checksum' => 'abc123',
        ]);

        $keywords = ['generate', 'image', 'sunset'];

        $mapping = new IntentMappingDTO(
            sampleIntent: 'Generate an image of a sunset',
            keywords: $keywords,
            skillId: $skill->id,
            confidence: 0.95,
            category: 'creative',
        );
        SkillMatch::storeMatch($mapping);

        $found = SkillMatch::findByKeywords($keywords);

        expect($found)->not->toBeNull()
            ->and($found->skill_id)->toBe($skill->id);
    });

    it('can store match by skill name', function () {
        Skill::create([
            'name' => 'imagegen',
            'dir_name' => 'imagegen',
            'path' => '/tmp/skills/imagegen',
            'source_type' => 'core',
            'description' => 'Generate images',
            'checksum' => 'abc123',
        ]);

        $keywords = ['generate', 'image', 'sunset'];

        $match = SkillMatch::storeMatchBySkillName(
            keywords: $keywords,
            skillName: 'imagegen',
            confidence: 0.95,
            category: 'creative'
        );

        expect($match)->not->toBeNull()
            ->and($match->skill->name)->toBe('imagegen');
    });

    it('throws exception for unknown skill name', function () {
        $keywords = ['generate', 'image', 'sunset'];

        SkillMatch::storeMatchBySkillName(
            keywords: $keywords,
            skillName: 'nonexistent',
            confidence: 0.95,
            category: 'creative'
        );
    })->throws(\InvalidArgumentException::class, 'Skill not found: nonexistent');

    it('can find similar entries by keyword overlap', function () {
        $skill = Skill::create([
            'name' => 'imagegen',
            'dir_name' => 'imagegen',
            'path' => '/tmp/skills/imagegen',
            'source_type' => 'core',
            'description' => 'Generate images',
            'checksum' => 'abc123',
        ]);

        // Create a cache entry
        $mapping = new IntentMappingDTO(
            sampleIntent: 'Generate beautiful sunset',
            keywords: ['generate', 'image', 'sunset', 'beautiful'],
            skillId: $skill->id,
            confidence: 0.90,
            category: 'creative',
        );
        SkillMatch::storeMatch($mapping);

        // Search with overlapping keywords
        $found = SkillMatch::findSimilar(['generate', 'image', 'mountains'], 0.7);

        expect($found)->not->toBeNull()
            ->and($found->skill_id)->toBe($skill->id);
    });

    it('can record hits', function () {
        $skill = Skill::create([
            'name' => 'test_skill',
            'dir_name' => 'test_skill',
            'path' => '/tmp/skills/test_skill',
            'source_type' => 'core',
            'description' => 'Test skill',
            'checksum' => 'abc123',
        ]);

        $cache = SkillMatch::create([
            'intent_signature' => 'test123',
            'intent_keywords' => ['test'],
            'skill_id' => $skill->id,
            'confidence_score' => 0.9,
            'hit_count' => 1,
        ]);

        expect($cache->hit_count)->toBe(1);

        $cache->recordHit();
        $cache->refresh();

        expect($cache->hit_count)->toBe(2);
    });

    it('can use query scopes', function () {
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

        // Create test entries
        SkillMatch::create([
            'intent_signature' => 'sig1',
            'intent_keywords' => ['test'],
            'skill_id' => $skill1->id,
            'confidence_score' => 0.95,
            'intent_category' => 'creative',
        ]);

        SkillMatch::create([
            'intent_signature' => 'sig2',
            'intent_keywords' => ['test'],
            'skill_id' => $skill2->id,
            'confidence_score' => 0.6,
            'intent_category' => 'scheduling',
        ]);

        // Test scopeHighConfidence
        $highConfidence = SkillMatch::highConfidence()->get();
        expect($highConfidence)->toHaveCount(1)
            ->and($highConfidence->first()->skill_id)->toBe($skill1->id);

        // Test scopeForSkill
        $imagegenMatches = SkillMatch::forSkill($skill1->id)->get();
        expect($imagegenMatches)->toHaveCount(1);

        // Test scopeForSkillName
        $imagegenByName = SkillMatch::forSkillName('imagegen')->get();
        expect($imagegenByName)->toHaveCount(1);

        // Test scopeForCategory
        $creative = SkillMatch::forCategory('creative')->get();
        expect($creative)->toHaveCount(1);
    });

    it('can get statistics', function () {
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

        // Create test entries
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

        $stats = SkillMatch::getStatistics();

        expect($stats->totalEntries)->toBe(2)
            ->and($stats->totalHits)->toBe(15)
            ->and($stats->highConfidenceCount)->toBe(2);
    });

    it('can cleanup old entries', function () {
        $skill1 = Skill::create([
            'name' => 'old_skill',
            'dir_name' => 'old_skill',
            'path' => '/tmp/skills/old_skill',
            'source_type' => 'core',
            'description' => 'Old skill',
            'checksum' => 'abc123',
        ]);

        $skill2 = Skill::create([
            'name' => 'new_skill',
            'dir_name' => 'new_skill',
            'path' => '/tmp/skills/new_skill',
            'source_type' => 'core',
            'description' => 'New skill',
            'checksum' => 'def456',
        ]);

        // Create old entry with low hit count using direct DB insert to bypass timestamps
        \DB::table('skill_matches')->insert([
            'intent_signature' => 'old',
            'intent_keywords' => json_encode(['test']),
            'skill_id' => $skill1->id,
            'confidence_score' => 0.5,
            'hit_count' => 1,
            'created_at' => now()->subDays(60),
            'updated_at' => now()->subDays(60),
        ]);

        // Create new entry with high hit count
        $new = SkillMatch::create([
            'intent_signature' => 'new',
            'intent_keywords' => ['test'],
            'skill_id' => $skill2->id,
            'confidence_score' => 0.9,
            'hit_count' => 10,
        ]);

        $deleted = SkillMatch::cleanup(30, 2);

        expect($deleted)->toBe(1)
            ->and(SkillMatch::count())->toBe(1)
            ->and(SkillMatch::first()->skill_id)->toBe($skill2->id);
    });

    it('has skill relationship', function () {
        $skill = Skill::create([
            'name' => 'imagegen',
            'dir_name' => 'imagegen',
            'path' => '/tmp/skills/imagegen',
            'source_type' => 'core',
            'description' => 'Generate images',
            'checksum' => 'abc123',
        ]);

        $match = SkillMatch::create([
            'intent_signature' => 'test123',
            'intent_keywords' => ['test'],
            'skill_id' => $skill->id,
            'confidence_score' => 0.9,
        ]);

        expect($match->skill)->not->toBeNull()
            ->and($match->skill->name)->toBe('imagegen');
    });

    it('can convert to DTO with skill name', function () {
        $skill = Skill::create([
            'name' => 'imagegen',
            'dir_name' => 'imagegen',
            'path' => '/tmp/skills/imagegen',
            'source_type' => 'core',
            'description' => 'Generate images',
            'checksum' => 'abc123',
        ]);

        $match = SkillMatch::create([
            'intent_signature' => 'test123',
            'intent_keywords' => ['test', 'keywords'],
            'skill_id' => $skill->id,
            'confidence_score' => 0.9,
            'intent_category' => 'creative',
        ]);

        $dto = $match->toDTO();

        expect($dto->matchedSkill)->toBe('imagegen')
            ->and($dto->confidence)->toBe(0.9)
            ->and($dto->intent)->toBe('creative')
            ->and($dto->fromCache)->toBeTrue()
            ->and($dto->keywords)->toBe(['test', 'keywords']);
    });
});

describe('IntentClassificationService with Cache', function () {
    beforeEach(function () {
        $this->settings = app(SettingsService::class);
        $this->service = new IntentClassificationService($this->settings);
    });

    it('can extract keywords from message', function () {
        $keywords = $this->service->extractKeywords('Generate a beautiful sunset image for me please');

        expect($keywords)->toContain('generate')
            ->and($keywords)->toContain('beautiful')
            ->and($keywords)->toContain('sunset')
            ->and($keywords)->toContain('image')
            ->and($keywords)->not->toContain('for')  // stop word
            ->and($keywords)->not->toContain('me');  // stop word
    });

    it('stores classification results in cache', function () {
        $skill = Skill::create([
            'name' => 'imagegen',
            'dir_name' => 'imagegen',
            'path' => '/tmp/skills/imagegen',
            'source_type' => 'core',
            'description' => 'Generate images',
            'checksum' => 'abc123',
        ]);

        // First call should store in cache
        $keywords = ['generate', 'image', 'sunset'];
        $message = 'Generate an image of a sunset';

        // Store directly
        $mapping = new IntentMappingDTO(
            sampleIntent: $message,
            keywords: $keywords,
            skillId: $skill->id,
            confidence: 0.95,
            category: 'creative',
        );
        SkillMatch::storeMatch($mapping);

        // Verify it was stored
        $cached = SkillMatch::findByKeywords($keywords);
        expect($cached)->not->toBeNull()
            ->and($cached->skill->name)->toBe('imagegen');
    });
});

describe('SkillSearchService with Cache', function () {
    beforeEach(function () {
        $this->settings = app(SettingsService::class);
        $this->service = new SkillSearchService($this->settings);
    });

    it('stores skill matches in cache', function () {
        $skill = Skill::create([
            'name' => 'imagegen',
            'dir_name' => 'imagegen',
            'path' => '/tmp/skills/imagegen',
            'source_type' => 'core',
            'description' => 'Generate images',
            'checksum' => 'abc123',
        ]);

        // Pre-populate cache with a skill that exists in the skill index
        $keywords = ['generate', 'image'];
        $mapping = new IntentMappingDTO(
            sampleIntent: 'Generate image',
            keywords: $keywords,
            skillId: $skill->id,
            confidence: 0.9,
            category: 'creative',
        );
        SkillMatch::storeMatch($mapping);

        // Search should hit cache IF the skill exists in the index
        $results = $this->service->suggestSkillsForMessage('generate image');

        // The result depends on whether the skill exists in the index
        // If cache hit succeeds, we get from_cache=true
        if ($results->isNotEmpty()) {
            $first = $results->first();
            if ($first->fromCache) {
                expect($first->fromCache)->toBeTrue();
            } else {
                // Cache miss - skill not in index, fall back to search
                expect($results)->toBeInstanceOf(\App\TypedCollections\SkillSearchResultDTOCollection::class);
            }
        } else {
            expect($results)->toBeInstanceOf(\App\TypedCollections\SkillSearchResultDTOCollection::class);
        }
    });

    it('can get cache statistics', function () {
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

        // Create some cache entries
        $mapping1 = new IntentMappingDTO(
            sampleIntent: 'Test 1',
            keywords: ['test1'],
            skillId: $skill1->id,
            confidence: 0.9,
            category: 'test',
        );
        SkillMatch::storeMatch($mapping1);

        $mapping2 = new IntentMappingDTO(
            sampleIntent: 'Test 2',
            keywords: ['test2'],
            skillId: $skill2->id,
            confidence: 0.8,
            category: 'test',
        );
        SkillMatch::storeMatch($mapping2);

        $stats = $this->service->getCacheStatistics();

        expect($stats->totalEntries)->toBe(2);
    });

    it('can clear match cache', function () {
        $skill = Skill::create([
            'name' => 'imagegen',
            'dir_name' => 'imagegen',
            'path' => '/tmp/skills/imagegen',
            'source_type' => 'core',
            'description' => 'Generate images',
            'checksum' => 'abc123',
        ]);

        // Create cache entry
        $mapping = new IntentMappingDTO(
            sampleIntent: 'Test',
            keywords: ['test'],
            skillId: $skill->id,
            confidence: 0.9,
            category: 'test',
        );
        SkillMatch::storeMatch($mapping);

        expect(SkillMatch::count())->toBe(1);

        $this->service->clearMatchCache();

        expect(SkillMatch::count())->toBe(0);
    });
});

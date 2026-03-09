<?php

use App\Models\Skill;
use App\Models\SkillMatch;
use App\Services\Skills\SkillMatchRepository;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    SkillMatch::query()->delete();
    Skill::query()->delete();
    Cache::flush();

    $this->repository = new SkillMatchRepository;
});

describe('SkillMatchRepository', function () {
    describe('storeMatch and findByKeywords', function () {
        it('stores and retrieves by keywords', function () {
            $skill = Skill::create([
                'name' => 'imagegen',
                'dir_name' => 'imagegen',
                'path' => '/tmp/skills/imagegen',
                'source_type' => 'core',
                'description' => 'Generate images',
                'checksum' => 'abc123',
            ]);

            $this->repository->storeMatch(
                keywords: ['generate', 'image', 'sunset'],
                skillId: $skill->id,
                confidence: 0.95,
                category: 'creative'
            );

            $found = $this->repository->findByKeywords(['generate', 'image', 'sunset']);

            expect($found)->not->toBeNull()
                ->and($found->skill_id)->toBe($skill->id);
        });
    });

    describe('storeMatchBySkillName', function () {
        it('stores by skill name', function () {
            Skill::create([
                'name' => 'imagegen',
                'dir_name' => 'imagegen',
                'path' => '/tmp/skills/imagegen',
                'source_type' => 'core',
                'description' => 'Generate images',
                'checksum' => 'abc123',
            ]);

            $match = $this->repository->storeMatchBySkillName(
                keywords: ['test'],
                skillName: 'imagegen',
                confidence: 0.9,
            );

            expect($match->skill->name)->toBe('imagegen');
        });

        it('throws for unknown skill name', function () {
            $this->repository->storeMatchBySkillName(
                keywords: ['test'],
                skillName: 'nonexistent',
                confidence: 0.9
            );
        })->throws(\InvalidArgumentException::class, 'Skill not found: nonexistent');
    });

    describe('findSimilar', function () {
        it('finds exact matches first', function () {
            $skill = Skill::create([
                'name' => 'imagegen',
                'dir_name' => 'imagegen',
                'path' => '/tmp/skills/imagegen',
                'source_type' => 'core',
                'description' => 'Generate images',
                'checksum' => 'abc123',
            ]);

            $this->repository->storeMatch(
                keywords: ['generate', 'image'],
                skillId: $skill->id,
                confidence: 0.95
            );

            $found = $this->repository->findSimilar(['generate', 'image'], 0.7);

            expect($found)->not->toBeNull()
                ->and($found->skill_id)->toBe($skill->id);
        });

        it('finds by keyword overlap when no exact match', function () {
            $skill = Skill::create([
                'name' => 'imagegen',
                'dir_name' => 'imagegen',
                'path' => '/tmp/skills/imagegen',
                'source_type' => 'core',
                'description' => 'Generate images',
                'checksum' => 'abc123',
            ]);

            $this->repository->storeMatch(
                keywords: ['generate', 'image', 'sunset', 'beautiful'],
                skillId: $skill->id,
                confidence: 0.90
            );

            $found = $this->repository->findSimilar(['generate', 'image', 'mountains'], 0.7);

            expect($found)->not->toBeNull()
                ->and($found->skill_id)->toBe($skill->id);
        });
    });

    describe('clearAll', function () {
        it('removes all entries', function () {
            $skill = Skill::create([
                'name' => 'test_skill',
                'dir_name' => 'test_skill',
                'path' => '/tmp/skills/test_skill',
                'source_type' => 'core',
                'description' => 'Test',
                'checksum' => 'abc123',
            ]);

            $this->repository->storeMatch(keywords: ['a'], skillId: $skill->id, confidence: 0.9);
            $this->repository->storeMatch(keywords: ['b'], skillId: $skill->id, confidence: 0.8);

            expect(SkillMatch::count())->toBe(2);

            $this->repository->clearAll();

            expect(SkillMatch::count())->toBe(0);
        });
    });

    describe('cleanup', function () {
        it('removes old entries with low hit counts', function () {
            $skill = Skill::create([
                'name' => 'test_skill',
                'dir_name' => 'test_skill',
                'path' => '/tmp/skills/test_skill',
                'source_type' => 'core',
                'description' => 'Test',
                'checksum' => 'abc123',
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

            $this->repository->storeMatch(
                keywords: ['new'],
                skillId: $skill->id,
                confidence: 0.9
            );

            $deleted = $this->repository->cleanup(30, 2);

            expect($deleted)->toBe(1)
                ->and(SkillMatch::count())->toBe(1);
        });
    });

    describe('generateSignature', function () {
        it('delegates to SignatureGenerator', function () {
            $sig1 = $this->repository->generateSignature(['b', 'a']);
            $sig2 = $this->repository->generateSignature(['a', 'b']);

            expect($sig1)->toBe($sig2);
        });
    });
});

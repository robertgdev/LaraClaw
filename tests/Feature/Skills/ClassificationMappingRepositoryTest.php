<?php

use App\DTOs\IntentMappingDTO;
use App\Models\Skill;
use App\Models\SkillMatch;
use App\Services\Skills\ClassificationMappingRepository;
use App\TypedCollections\IntentMappingDTOCollection;

beforeEach(function () {
    $this->repository = new ClassificationMappingRepository;
    SkillMatch::query()->delete();
    Skill::query()->delete();
});

afterEach(function () {
    SkillMatch::query()->delete();
    Skill::query()->delete();
});

describe('ClassificationMappingRepository', function () {
    describe('storeMappings', function () {
        it('stores mappings in database', function () {
            $skill = Skill::create([
                'name' => 'imagegen',
                'dir_name' => 'imagegen',
                'path' => '/tmp/skills/imagegen',
                'source_type' => 'core',
                'description' => 'Generate images',
                'checksum' => 'abc123',
            ]);

            $mappings = new IntentMappingDTOCollection([
                new IntentMappingDTO(
                    sampleIntent: 'Generate an image',
                    keywords: ['generate', 'image'],
                    skillId: $skill->id,
                    confidence: 0.95,
                    category: 'creative',
                ),
            ]);

            $stored = $this->repository->storeMappings($mappings);

            expect($stored)->toBe(1)
                ->and(SkillMatch::count())->toBe(1);
        });

        it('clears existing preclassification entries for same skill', function () {
            $skill = Skill::create([
                'name' => 'test-skill',
                'dir_name' => 'test-skill',
                'path' => '/tmp/skills/test-skill',
                'source_type' => 'core',
                'description' => 'Test',
                'checksum' => 'abc',
            ]);

            // Store initial mapping
            $oldMapping = new IntentMappingDTO(
                sampleIntent: 'Old mapping',
                keywords: ['old'],
                skillId: $skill->id,
                confidence: 0.8,
                category: 'test',
            );
            SkillMatch::storeMatch($oldMapping, metadata: ['source' => 'preclassification']);

            expect(SkillMatch::count())->toBe(1);

            // Store new mappings (should clear old ones)
            $mappings = new IntentMappingDTOCollection([
                new IntentMappingDTO(
                    sampleIntent: 'New mapping',
                    keywords: ['new'],
                    skillId: $skill->id,
                    confidence: 0.9,
                    category: 'test',
                ),
            ]);

            $stored = $this->repository->storeMappings($mappings);

            expect($stored)->toBe(1)
                ->and(SkillMatch::count())->toBe(1);
        });

        it('handles empty mappings', function () {
            $stored = $this->repository->storeMappings(new IntentMappingDTOCollection([]));

            expect($stored)->toBe(0);
        });

        it('skips mappings without skill_id', function () {
            $stored = $this->repository->storeMappings(new IntentMappingDTOCollection([
                new IntentMappingDTO(
                    sampleIntent: 'No skill',
                    keywords: ['test'],
                    skillId: null,
                    confidence: 0.9,
                    category: 'test',
                ),
            ]));

            expect($stored)->toBe(0);
        });

        it('stores mappings for multiple skills', function () {
            $skill1 = Skill::create([
                'name' => 'skill1', 'dir_name' => 'skill1', 'path' => '/tmp/s1',
                'source_type' => 'core', 'description' => 'S1', 'checksum' => 'c1',
            ]);
            $skill2 = Skill::create([
                'name' => 'skill2', 'dir_name' => 'skill2', 'path' => '/tmp/s2',
                'source_type' => 'core', 'description' => 'S2', 'checksum' => 'c2',
            ]);

            $mappings = new IntentMappingDTOCollection([
                new IntentMappingDTO(
                    sampleIntent: 'For skill 1',
                    keywords: ['s1'],
                    skillId: $skill1->id,
                    confidence: 0.9,
                    category: 'test',
                ),
                new IntentMappingDTO(
                    sampleIntent: 'For skill 2',
                    keywords: ['s2'],
                    skillId: $skill2->id,
                    confidence: 0.8,
                    category: 'test',
                ),
            ]);

            $stored = $this->repository->storeMappings($mappings);

            expect($stored)->toBe(2)
                ->and(SkillMatch::count())->toBe(2);
        });
    });
});

<?php

use App\Models\Skill;
use App\Models\SkillMatch;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    // Clear skills before each test
    Skill::query()->delete();
    SkillMatch::query()->delete();
});

describe('Skill Model', function () {
    describe('Creation and Basic Operations', function () {
        it('can create a skill', function () {
            $skill = Skill::create([
                'name' => 'imagegen',
                'dir_name' => 'imagegen',
                'path' => '/tmp/skills/imagegen',
                'source_type' => 'default',
                'description' => 'Generate images using AI',
                'checksum' => 'abc123',
            ]);

            expect($skill)->not->toBeNull()
                ->and($skill->name)->toBe('imagegen')
                ->and($skill->source_type)->toBe('default')
                ->and($skill->classification_status)->toBe(Skill::STATUS_PENDING)
                ->and($skill->is_active)->toBeTrue();
        });

        it('enforces unique skill names', function () {
            Skill::create([
                'name' => 'imagegen',
                'dir_name' => 'imagegen',
                'path' => '/tmp/skills/imagegen',
                'source_type' => 'core',
                'description' => 'Generate images',
                'checksum' => 'abc123',
            ]);

            // This should fail due to unique constraint
            Skill::create([
                'name' => 'imagegen',
                'dir_name' => 'imagegen2',
                'path' => '/tmp/skills/imagegen2',
                'source_type' => 'default',
                'description' => 'Another image generator',
                'checksum' => 'def456',
            ]);
        })->throws(\Illuminate\Database\QueryException::class);

        it('casts keywords and metadata as arrays', function () {
            $skill = Skill::create([
                'name' => 'test-skill',
                'dir_name' => 'test-skill',
                'path' => '/tmp/skills/test-skill',
                'source_type' => 'default',
                'description' => 'Test skill',
                'checksum' => 'abc123',
                'keywords' => ['test', 'skill', 'keywords'],
                'metadata' => ['custom' => 'data', 'version' => 1],
            ]);

            expect($skill->keywords)->toBe(['test', 'skill', 'keywords'])
                ->and($skill->metadata)->toBe(['custom' => 'data', 'version' => 1]);
        });

        it('casts boolean fields correctly', function () {
            $skill = Skill::create([
                'name' => 'test-skill',
                'dir_name' => 'test-skill',
                'path' => '/tmp/skills/test-skill',
                'source_type' => 'default',
                'description' => 'Test skill',
                'checksum' => 'abc123',
                'has_scripts' => true,
                'has_references' => true,
                'has_assets' => false,
                'is_active' => false,
            ]);

            expect($skill->has_scripts)->toBeTrue()
                ->and($skill->has_references)->toBeTrue()
                ->and($skill->has_assets)->toBeFalse()
                ->and($skill->is_active)->toBeFalse();
        });
    });

    describe('Query Scopes', function () {
        beforeEach(function () {
            // Create test skills
            Skill::create([
                'name' => 'custom-skill',
                'dir_name' => 'custom-skill',
                'path' => '/tmp/skills/custom-skill',
                'source_type' => 'default',
                'description' => 'Custom skill',
                'checksum' => 'abc1',
                'classification_status' => Skill::STATUS_CLASSIFIED,
                'is_active' => true,
            ]);

            Skill::create([
                'name' => 'core-skill',
                'dir_name' => 'core-skill',
                'path' => '/tmp/skills/core-skill',
                'source_type' => 'default',
                'description' => 'Core skill',
                'checksum' => 'abc2',
                'classification_status' => Skill::STATUS_PENDING,
                'is_active' => true,
            ]);

            Skill::create([
                'name' => 'failed-skill',
                'dir_name' => 'failed-skill',
                'path' => '/tmp/skills/failed-skill',
                'source_type' => 'external',
                'description' => 'Failed skill',
                'checksum' => 'abc3',
                'classification_status' => Skill::STATUS_FAILED,
                'is_active' => false,
            ]);
        });

        it('can filter by active status', function () {
            $active = Skill::active()->get();
            $inactive = Skill::inactive()->get();

            expect($active)->toHaveCount(2)
                ->and($inactive)->toHaveCount(1)
                ->and($inactive->first()->name)->toBe('failed-skill');
        });

        it('can filter by classification status', function () {
            $pending = Skill::pending()->get();
            $classified = Skill::classified()->get();
            $failed = Skill::failed()->get();

            expect($pending)->toHaveCount(1)
                ->and($classified)->toHaveCount(1)
                ->and($failed)->toHaveCount(1);
        });

        it('can filter by checksum', function () {
            $skill = Skill::withChecksum('abc1')->first();

            expect($skill)->not->toBeNull()
                ->and($skill->name)->toBe('custom-skill');
        });
    });

    describe('Static Helper Methods', function () {
        it('can find skill by name', function () {
            Skill::create([
                'name' => 'imagegen',
                'dir_name' => 'imagegen',
                'path' => '/tmp/skills/imagegen',
                'source_type' => 'default',
                'description' => 'Generate images',
                'checksum' => 'abc123',
            ]);

            $found = Skill::findByName('imagegen');
            $notFound = Skill::findByName('nonexistent');

            expect($found)->not->toBeNull()
                ->and($found->name)->toBe('imagegen')
                ->and($notFound)->toBeNull();
        });

        it('can calculate checksum for a directory', function () {
            // Create a temporary directory with files
            $tempDir = sys_get_temp_dir().'/skill_test_'.uniqid();
            mkdir($tempDir);
            file_put_contents($tempDir.'/SKILL.md', '---\nname: test\n---\nContent');
            file_put_contents($tempDir.'/script.sh', '#!/bin/bash\necho "test"');

            $checksum = Skill::calculateChecksum($tempDir);

            expect($checksum)->toBeString()
                ->and(strlen($checksum))->toBe(64); // SHA-256 produces 64 hex chars

            // Clean up
            unlink($tempDir.'/SKILL.md');
            unlink($tempDir.'/script.sh');
            rmdir($tempDir);
        });

        it('returns empty checksum for non-existent directory', function () {
            $checksum = Skill::calculateChecksum('/nonexistent/directory');

            expect($checksum)->toBe('');
        });

        it('can sync skills from index', function () {
            $indexedSkills = [
                'imagegen' => [
                    'name' => 'imagegen',
                    'dir_name' => 'imagegen',
                    'path' => '/tmp/skills/imagegen',
                    'directory' => '/tmp/skills/imagegen',
                    'source_type' => 'default',
                    'description' => 'Generate images',
                    'keywords' => ['image', 'generate'],
                    'has_scripts' => true,
                    'has_references' => false,
                    'has_assets' => false,
                ],
                'schedule' => [
                    'name' => 'schedule',
                    'dir_name' => 'schedule',
                    'path' => '/tmp/skills/schedule',
                    'directory' => '/tmp/skills/schedule',
                    'source_type' => 'default',
                    'description' => 'Schedule tasks',
                    'keywords' => ['schedule', 'task'],
                    'has_scripts' => true,
                    'has_references' => true,
                    'has_assets' => false,
                ],
            ];

            $stats = Skill::syncFromIndex($indexedSkills);

            expect($stats['created'])->toBe(2)
                ->and($stats['updated'])->toBe(0)
                ->and($stats['deactivated'])->toBe(0)
                ->and(Skill::count())->toBe(2);
        });

        it('deactivates skills not in index during sync', function () {
            // Create an existing skill
            Skill::create([
                'name' => 'old-skill',
                'dir_name' => 'old-skill',
                'path' => '/tmp/skills/old-skill',
                'source_type' => 'default',
                'description' => 'Old skill',
                'checksum' => 'old123',
                'is_active' => true,
            ]);

            // Sync with new skills (not including old-skill)
            $indexedSkills = [
                'new-skill' => [
                    'name' => 'new-skill',
                    'dir_name' => 'new-skill',
                    'path' => '/tmp/skills/new-skill',
                    'directory' => '/tmp/skills/new-skill',
                    'source_type' => 'default',
                    'description' => 'New skill',
                    'keywords' => [],
                ],
            ];

            $stats = Skill::syncFromIndex($indexedSkills);

            expect($stats['deactivated'])->toBe(1)
                ->and(Skill::active()->count())->toBe(1)
                ->and(Skill::inactive()->count())->toBe(1);
        });

        it('resets classification status when checksum changes', function () {
            // Create an existing classified skill
            $skill = Skill::create([
                'name' => 'imagegen',
                'dir_name' => 'imagegen',
                'path' => '/tmp/skills/imagegen',
                'source_type' => 'default',
                'description' => 'Generate images',
                'checksum' => 'old_checksum',
                'classification_status' => Skill::STATUS_CLASSIFIED,
            ]);

            // Sync with different checksum (simulating file change)
            $indexedSkills = [
                'imagegen' => [
                    'name' => 'imagegen',
                    'dir_name' => 'imagegen',
                    'path' => '/tmp/skills/imagegen',
                    'directory' => '/tmp/skills/imagegen',
                    'source_type' => 'default',
                    'description' => 'Generate images - updated!',
                    'keywords' => [],
                ],
            ];

            Skill::syncFromIndex($indexedSkills);

            $skill->refresh();
            expect($skill->classification_status)->toBe(Skill::STATUS_PENDING);
        });

        it('can get classification stats', function () {
            Skill::create([
                'name' => 'classified-skill',
                'dir_name' => 'classified-skill',
                'path' => '/tmp/skills/classified-skill',
                'source_type' => 'default',
                'description' => 'Classified',
                'checksum' => 'abc1',
                'classification_status' => Skill::STATUS_CLASSIFIED,
                'intents_count' => 5,
                'is_active' => true,
            ]);

            Skill::create([
                'name' => 'pending-skill',
                'dir_name' => 'pending-skill',
                'path' => '/tmp/skills/pending-skill',
                'source_type' => 'default',
                'description' => 'Pending',
                'checksum' => 'abc2',
                'classification_status' => Skill::STATUS_PENDING,
                'is_active' => true,
            ]);

            $stats = Skill::getClassificationStats();

            expect($stats->total)->toBe(2)
                ->and($stats->classified)->toBe(1)
                ->and($stats->pending)->toBe(1)
                ->and($stats->totalIntents)->toBe(5);
        });
    });

    describe('Instance Methods', function () {
        it('can check if needs classification', function () {
            $pending = Skill::create([
                'name' => 'pending',
                'dir_name' => 'pending',
                'path' => '/tmp/skills/pending',
                'source_type' => 'default',
                'description' => 'Pending',
                'checksum' => 'abc1',
                'classification_status' => Skill::STATUS_PENDING,
            ]);

            $classified = Skill::create([
                'name' => 'classified',
                'dir_name' => 'classified',
                'path' => '/tmp/skills/classified',
                'source_type' => 'default',
                'description' => 'Classified',
                'checksum' => 'abc2',
                'classification_status' => Skill::STATUS_CLASSIFIED,
            ]);

            expect($pending->isPendingOrFailed())->toBeTrue()
                ->and($classified->isPendingOrFailed())->toBeFalse();
        });

        it('can mark as classified', function () {
            $skill = Skill::create([
                'name' => 'test',
                'dir_name' => 'test',
                'path' => '/tmp/skills/test',
                'source_type' => 'default',
                'description' => 'Test',
                'checksum' => 'abc1',
                'classification_status' => Skill::STATUS_PENDING,
            ]);

            $skill->markClassified(5, 'openai', 'gpt-4o-mini');
            $skill->refresh();

            expect($skill->classification_status)->toBe(Skill::STATUS_CLASSIFIED)
                ->and($skill->intents_count)->toBe(5)
                ->and($skill->classification_provider)->toBe('openai')
                ->and($skill->classification_model)->toBe('gpt-4o-mini')
                ->and($skill->classified_at)->not->toBeNull()
                ->and($skill->last_error)->toBeNull();
        });

        it('can mark as failed', function () {
            $skill = Skill::create([
                'name' => 'test',
                'dir_name' => 'test',
                'path' => '/tmp/skills/test',
                'source_type' => 'default',
                'description' => 'Test',
                'checksum' => 'abc1',
                'classification_status' => Skill::STATUS_PENDING,
            ]);

            $skill->markFailed('API timeout');
            $skill->refresh();

            expect($skill->classification_status)->toBe(Skill::STATUS_FAILED)
                ->and($skill->last_error)->toBe('API timeout');
        });

        it('can mark as skipped', function () {
            $skill = Skill::create([
                'name' => 'test',
                'dir_name' => 'test',
                'path' => '/tmp/skills/test',
                'source_type' => 'default',
                'description' => 'Test',
                'checksum' => 'abc1',
                'classification_status' => Skill::STATUS_PENDING,
            ]);

            $skill->markSkipped();
            $skill->refresh();

            expect($skill->classification_status)->toBe(Skill::STATUS_SKIPPED);
        });
    });

    describe('Relationships', function () {
        it('has many skill matches', function () {
            $skill = Skill::create([
                'name' => 'imagegen',
                'dir_name' => 'imagegen',
                'path' => '/tmp/skills/imagegen',
                'source_type' => 'default',
                'description' => 'Generate images',
                'checksum' => 'abc123',
            ]);

            SkillMatch::create([
                'intent_signature' => 'sig1',
                'intent_keywords' => ['test'],
                'skill_id' => $skill->id,
                'confidence_score' => 0.9,
            ]);

            SkillMatch::create([
                'intent_signature' => 'sig2',
                'intent_keywords' => ['test2'],
                'skill_id' => $skill->id,
                'confidence_score' => 0.8,
            ]);

            expect($skill->matches)->toHaveCount(2);
        });

        it('deletes matches when skill is deleted', function () {
            $skill = Skill::create([
                'name' => 'imagegen',
                'dir_name' => 'imagegen',
                'path' => '/tmp/skills/imagegen',
                'source_type' => 'default',
                'description' => 'Generate images',
                'checksum' => 'abc123',
            ]);

            SkillMatch::create([
                'intent_signature' => 'sig1',
                'intent_keywords' => ['test'],
                'skill_id' => $skill->id,
                'confidence_score' => 0.9,
            ]);

            expect(SkillMatch::count())->toBe(1);

            $skill->delete();

            expect(SkillMatch::count())->toBe(0);
        });

        it('can clear all matches', function () {
            $skill = Skill::create([
                'name' => 'imagegen',
                'dir_name' => 'imagegen',
                'path' => '/tmp/skills/imagegen',
                'source_type' => 'default',
                'description' => 'Generate images',
                'checksum' => 'abc123',
            ]);

            SkillMatch::create([
                'intent_signature' => 'sig1',
                'intent_keywords' => ['test'],
                'skill_id' => $skill->id,
                'confidence_score' => 0.9,
            ]);

            expect(SkillMatch::count())->toBe(1);

            $skill->clearMatches();

            expect(SkillMatch::count())->toBe(0);
        });
    });
});

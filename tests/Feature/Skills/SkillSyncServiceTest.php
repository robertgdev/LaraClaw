<?php

use App\Models\Skill;
use App\Services\Skills\SkillChecksumCalculator;
use App\Services\Skills\SkillSyncService;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->calculator = new SkillChecksumCalculator;
    $this->syncService = new SkillSyncService($this->calculator);
    Skill::query()->delete();
});

afterEach(function () {
    Skill::query()->delete();
});

describe('SkillSyncService', function () {
    describe('syncFromIndex', function () {
        it('creates new skills from index', function () {
            $dir = sys_get_temp_dir().'/sync_test_'.uniqid();
            mkdir($dir);
            file_put_contents($dir.'/SKILL.md', 'Test skill');

            $result = $this->syncService->syncFromIndex([
                'test-skill' => [
                    'name' => 'test-skill',
                    'dir_name' => 'test-skill',
                    'path' => $dir,
                    'directory' => $dir,
                    'source_type' => 'core',
                    'description' => 'Test skill',
                    'keywords' => ['test'],
                ],
            ]);

            expect($result['created'])->toBe(1)
                ->and($result['updated'])->toBe(0)
                ->and(Skill::count())->toBe(1);

            File::deleteDirectory($dir);
        });

        it('preserves classification status when checksum unchanged', function () {
            $dir = sys_get_temp_dir().'/sync_preserve_'.uniqid();
            mkdir($dir);
            file_put_contents($dir.'/SKILL.md', 'Test content');

            $checksum = $this->calculator->calculate($dir);

            // Create a classified skill
            Skill::create([
                'name' => 'classified-skill',
                'dir_name' => 'classified-skill',
                'path' => $dir,
                'source_type' => 'core',
                'description' => 'Classified',
                'checksum' => $checksum,
                'classification_status' => Skill::STATUS_CLASSIFIED,
                'intents_count' => 5,
            ]);

            // Sync with same directory (same checksum)
            $this->syncService->syncFromIndex([
                'classified-skill' => [
                    'name' => 'classified-skill',
                    'dir_name' => 'classified-skill',
                    'path' => $dir,
                    'directory' => $dir,
                    'source_type' => 'core',
                    'description' => 'Classified',
                    'keywords' => [],
                ],
            ]);

            $skill = Skill::findByName('classified-skill');
            expect($skill->classification_status)->toBe(Skill::STATUS_CLASSIFIED);

            File::deleteDirectory($dir);
        });

        it('deactivates skills not in index', function () {
            Skill::create([
                'name' => 'old-skill',
                'dir_name' => 'old-skill',
                'path' => '/tmp/old',
                'source_type' => 'core',
                'description' => 'Old skill',
                'checksum' => 'abc123',
                'is_active' => true,
            ]);

            $result = $this->syncService->syncFromIndex([]);

            expect($result['deactivated'])->toBe(1);
            $skill = Skill::findByName('old-skill');
            expect($skill->is_active)->toBeFalse();
        });
    });
});

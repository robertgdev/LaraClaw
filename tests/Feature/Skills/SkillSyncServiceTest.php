<?php

use App\DTOs\ParsedSkillDTO;
use App\Models\Skill;
use App\Services\Skills\SkillChecksumCalculator;
use App\Services\Skills\SkillSyncService;
use App\TypedCollections\ParsedSkillDTOCollection;
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

            $skillDto = new ParsedSkillDTO(
                name: 'test-skill',
                dirName: 'test-skill',
                description: 'Test skill',
                path: $dir.'/SKILL.md',
                directory: $dir,
                keywords: ['test'],
                hasScripts: false,
                hasReferences: false,
                hasAssets: false,
                license: null,
            );

            $result = $this->syncService->syncFromIndex(
                new ParsedSkillDTOCollection([$skillDto])
            );

            expect($result->created)->toBe(1)
                ->and($result->updated)->toBe(0)
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

            $skillDto = new ParsedSkillDTO(
                name: 'classified-skill',
                dirName: 'classified-skill',
                description: 'Classified',
                path: $dir.'/SKILL.md',
                directory: $dir,
                keywords: ['test'],
                hasScripts: false,
                hasReferences: false,
                hasAssets: false,
                license: null,
            );

            // Sync with same directory (same checksum)
            $result = $this->syncService->syncFromIndex(
                new ParsedSkillDTOCollection([$skillDto])
            );

            // Status should be preserved
            $skill = Skill::where('name', 'classified-skill')->first();
            expect($skill->classification_status)->toBe(Skill::STATUS_CLASSIFIED);

            File::deleteDirectory($dir);
        });

        it('deactivates skills not in index', function () {
            // Create an existing skill
            Skill::create([
                'name' => 'old-skill',
                'dir_name' => 'old-skill',
                'path' => '/tmp/old-skill',
                'source_type' => 'core',
                'description' => 'Old skill',
                'checksum' => 'old-checksum',
                'is_active' => true,
            ]);

            $dir = sys_get_temp_dir().'/sync_deactivate_'.uniqid();
            mkdir($dir);
            file_put_contents($dir.'/SKILL.md', 'New skill');

            $skillDto = new ParsedSkillDTO(
                name: 'new-skill',
                dirName: 'new-skill',
                description: 'New skill',
                path: $dir.'/SKILL.md',
                directory: $dir,
                keywords: ['new'],
                hasScripts: false,
                hasReferences: false,
                hasAssets: false,
                license: null,
            );

            // Sync with only new skill
            $result = $this->syncService->syncFromIndex(
                new ParsedSkillDTOCollection([$skillDto])
            );

            expect($result->created)->toBe(1)
                ->and($result->deactivated)->toBe(1);

            $oldSkill = Skill::where('name', 'old-skill')->first();
            expect($oldSkill->is_active)->toBeFalse();

            File::deleteDirectory($dir);
        });
    });
});

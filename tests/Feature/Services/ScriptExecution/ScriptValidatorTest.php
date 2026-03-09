<?php

use App\Services\ScriptExecution\ScriptValidator;
use App\Services\SkillSearchService;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->testWorkspace = storage_path('app/laraclaw/test-validator-'.uniqid('', true));
    $this->testSkillDir = $this->testWorkspace.'/.agents/skills/test-skill';
    File::makeDirectory($this->testSkillDir.'/scripts', 0755, true);

    File::put($this->testSkillDir.'/scripts/test.sh', '#!/bin/bash\necho test');
    chmod($this->testSkillDir.'/scripts/test.sh', 0755);

    File::put($this->testSkillDir.'/scripts/test.exe', 'binary');

    $this->skillSearch = Mockery::mock(SkillSearchService::class);
    $this->skillSearch->shouldReceive('getSkill')
        ->with('test-skill')
        ->andReturn([
            'name' => 'test-skill',
            'directory' => $this->testSkillDir,
            'has_scripts' => true,
        ]);
    $this->skillSearch->shouldReceive('getSkill')
        ->withArgs(fn ($name) => $name !== 'test-skill')
        ->andReturn(null);
    $this->skillSearch->shouldReceive('getSkillsDirs')
        ->andReturn([
            ['type' => 'core', 'path' => $this->testWorkspace.'/.agents/skills'],
        ]);

    $this->validator = new ScriptValidator($this->skillSearch);
});

afterEach(function () {
    if (File::isDirectory($this->testWorkspace)) {
        File::deleteDirectory($this->testWorkspace);
    }
    Mockery::close();
});

describe('ScriptValidator', function () {
    describe('resolveScriptPath', function () {
        it('resolves valid script paths', function () {
            $skill = ['directory' => $this->testSkillDir];
            $path = $this->validator->resolveScriptPath($skill, 'test.sh');

            expect($path)->not->toBeNull()
                ->and($path)->toContain('test.sh');
        });

        it('prevents directory traversal with ..', function () {
            $skill = ['directory' => $this->testSkillDir];
            $path = $this->validator->resolveScriptPath($skill, '../../../etc/passwd');

            expect($path)->toBeNull();
        });

        it('prevents directory traversal with /', function () {
            $skill = ['directory' => $this->testSkillDir];
            $path = $this->validator->resolveScriptPath($skill, 'subdir/test.sh');

            expect($path)->toBeNull();
        });

        it('returns null for nonexistent scripts', function () {
            $skill = ['directory' => $this->testSkillDir];
            $path = $this->validator->resolveScriptPath($skill, 'nonexistent.sh');

            expect($path)->toBeNull();
        });
    });

    describe('isExtensionAllowed', function () {
        it('allows default extensions', function () {
            expect($this->validator->isExtensionAllowed('/path/to/script.sh'))->toBeTrue()
                ->and($this->validator->isExtensionAllowed('/path/to/script.py'))->toBeTrue()
                ->and($this->validator->isExtensionAllowed('/path/to/script.ts'))->toBeTrue()
                ->and($this->validator->isExtensionAllowed('/path/to/script.js'))->toBeTrue();
        });

        it('rejects disallowed extensions', function () {
            expect($this->validator->isExtensionAllowed('/path/to/script.exe'))->toBeFalse()
                ->and($this->validator->isExtensionAllowed('/path/to/script.rb'))->toBeFalse();
        });

        it('accepts custom extensions', function () {
            $validator = new ScriptValidator($this->skillSearch, ['rb', 'go']);

            expect($validator->isExtensionAllowed('/path/to/script.rb'))->toBeTrue()
                ->and($validator->isExtensionAllowed('/path/to/script.sh'))->toBeFalse();
        });
    });

    describe('validate', function () {
        it('validates existing scripts', function () {
            $result = $this->validator->validate('test-skill', 'test.sh');

            expect($result['valid'])->toBeTrue()
                ->and($result['error'])->toBeNull()
                ->and($result['path'])->not->toBeNull();
        });

        it('rejects nonexistent skills', function () {
            $result = $this->validator->validate('nonexistent', 'test.sh');

            expect($result['valid'])->toBeFalse()
                ->and($result['error'])->toContain('Skill not found');
        });

        it('reports disabled execution', function () {
            $result = $this->validator->validate('test-skill', 'test.sh', false);

            expect($result['valid'])->toBeFalse()
                ->and($result['error'])->toContain('disabled');
        });

        it('rejects disallowed extensions', function () {
            $result = $this->validator->validate('test-skill', 'test.exe');

            expect($result['valid'])->toBeFalse()
                ->and($result['error'])->toContain('not allowed');
        });
    });
});

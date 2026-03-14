<?php

declare(strict_types=1);

use App\DTOs\ScriptExecutionResultDTO;
use App\DTOs\SkillDTO;
use App\Services\ScriptExecutionService;
use App\Services\SettingsService;
use App\Services\SkillSearchService;
use App\TypedCollections\SkillDTOCollection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

// Helper function to create test scripts
function createTestScriptInDir(string $dir, string $name, string $content): void
{
    $path = $dir.'/scripts/'.$name;
    File::put($path, $content);
    chmod($path, 0755);
}

beforeEach(function () {
    // Create test workspace with unique ID
    $this->testWorkspace = storage_path('app/laraclaw/test-workspace-'.uniqid('', true));
    $this->testSkillDir = $this->testWorkspace.'/.agents/skills/test-skill';

    // Create directories
    File::makeDirectory($this->testSkillDir.'/scripts', 0755, true);

    // Create test SKILL.md
    File::put($this->testSkillDir.'/SKILL.md', <<<'MD'
---
name: test-skill
description: "A test skill for unit testing"
---

# Test Skill

This is a test skill for unit testing script execution.
MD);

    // Create test scripts
    createTestScriptInDir($this->testSkillDir, 'echo_test.sh', <<<'SH'
#!/bin/bash
echo "Hello from test script"
if [ -n "$1" ]; then
    echo "Argument: $1"
fi
SH);

    createTestScriptInDir($this->testSkillDir, 'echo_args.sh', <<<'SH'
#!/bin/bash
for arg in "$@"; do
    echo "Arg: $arg"
done
SH);

    createTestScriptInDir($this->testSkillDir, 'slow_script.sh', <<<'SH'
#!/bin/bash
sleep 10
echo "Done"
SH);

    createTestScriptInDir($this->testSkillDir, 'fail_script.sh', <<<'SH'
#!/bin/bash
echo "This is an error message" >&2
exit 1
SH);

    createTestScriptInDir($this->testSkillDir, 'dangerous.sh', <<<'SH'
#!/bin/bash
rm -rf /
SH);

    // Mock settings
    $this->settings = Mockery::mock(SettingsService::class);
    $this->settings->shouldReceive('getWorkspacePath')->andReturn($this->testWorkspace);

    // Create test skill DTO
    $this->testSkillDTO = new SkillDTO(
        name: 'test-skill',
        dirName: 'test-skill',
        description: 'A test skill for unit testing',
        path: $this->testSkillDir.'/SKILL.md',
        directory: $this->testSkillDir,
        keywords: [],
        hasScripts: true,
        hasReferences: false,
        hasAssets: false,
        license: null,
        sourceType: 'local'
    );

    // Mock skill search
    $this->skillSearch = Mockery::mock(SkillSearchService::class);
    $this->skillSearch->shouldReceive('getSkill')
        ->with('test-skill')
        ->andReturn($this->testSkillDTO);
    $this->skillSearch->shouldReceive('getSkill')
        ->withArgs(fn ($name) => $name !== 'test-skill')
        ->andReturn(null);
    $this->skillSearch->shouldReceive('getSkillsDirs')
        ->andReturn([
            ['type' => 'core', 'path' => $this->testWorkspace.'/.agents/skills'],
        ]);
    $this->skillSearch->shouldReceive('getAllSkills')
        ->andReturn(SkillDTOCollection::make([$this->testSkillDTO]));

    // Set config
    Config::set('laraclaw.script_execution', [
        'enabled' => true,
        'timeout' => 5,
        'max_output_size' => 1000,
        'allowed_extensions' => ['sh', 'py', 'ts', 'js'],
        'blocked_commands' => [
            'rm -rf /',
            'sudo ',
            'chmod 777',
        ],
    ]);

    $this->service = new ScriptExecutionService($this->settings, $this->skillSearch);
});

afterEach(function () {
    // Clean up test workspace
    if (File::isDirectory($this->testWorkspace)) {
        File::deleteDirectory($this->testWorkspace);
    }

    Mockery::close();
});

describe('ScriptExecutionService', function () {
    describe('script execution', function () {
        it('can execute a simple script', function () {
            $result = $this->service->execute(
                skillName: 'test-skill',
                scriptName: 'echo_test.sh'
            );

            expect($result->success)->toBeTrue()
                ->and($result->output)->toContain('Hello from test script')
                ->and($result->exitCode)->toBe(0);
        });

        it('can pass arguments to script', function () {
            $result = $this->service->execute(
                skillName: 'test-skill',
                scriptName: 'echo_args.sh',
                args: ['first', 'second', 'third']
            );

            expect($result->success)->toBeTrue()
                ->and($result->output)->toContain('Arg: first')
                ->and($result->output)->toContain('Arg: second')
                ->and($result->output)->toContain('Arg: third');
        });

        it('returns error for nonexistent skill', function () {
            $result = $this->service->execute(
                skillName: 'nonexistent-skill',
                scriptName: 'test.sh'
            );

            expect($result->success)->toBeFalse()
                ->and($result->error)->toContain('Skill not found');
        });

        it('returns error for nonexistent script', function () {
            $result = $this->service->execute(
                skillName: 'test-skill',
                scriptName: 'nonexistent.sh'
            );

            expect($result->success)->toBeFalse()
                ->and($result->error)->toContain('Script not found');
        });

        it('handles dangerous script content', function () {
            // Note: The blocking check is on the command string, not script content.
            // The script runs but the OS blocks 'rm -rf /' with its own protection.
            $result = $this->service->execute(
                skillName: 'test-skill',
                scriptName: 'dangerous.sh'
            );

            // The script runs but fails due to OS protection
            expect($result->success)->toBeFalse()
                ->and($result->error)->toContain('dangerous');
        });

        it('handles script failure', function () {
            $result = $this->service->execute(
                skillName: 'test-skill',
                scriptName: 'fail_script.sh'
            );

            expect($result->success)->toBeFalse()
                ->and($result->exitCode)->toBe(1)
                ->and($result->error)->toContain('error message');
        });

        it('rejects disallowed extensions', function () {
            // Create a script with disallowed extension
            createTestScriptInDir($this->testSkillDir, 'test.exe', '#!/bin/bash
echo "test"');

            $result = $this->service->execute(
                skillName: 'test-skill',
                scriptName: 'test.exe'
            );

            expect($result->success)->toBeFalse()
                ->and($result->error)->toContain('exe');
        });

        it('prevents directory traversal', function () {
            $result = $this->service->execute(
                skillName: 'test-skill',
                scriptName: '../../../etc/passwd'
            );

            expect($result->success)->toBeFalse()
                ->and($result->error)->toContain('Script not found');
        });
    });

    describe('validation', function () {
        it('can validate script without executing', function () {
            $validation = $this->service->validateScript('test-skill', 'echo_test.sh');

            expect($validation['valid'])->toBeTrue()
                ->and($validation['error'])->toBeNull()
                ->and($validation['path'])->not->toBeNull();
        });

        it('can validate nonexistent script', function () {
            $validation = $this->service->validateScript('test-skill', 'nonexistent.sh');

            expect($validation['valid'])->toBeFalse()
                ->and($validation['error'])->toContain('Script not found');
        });
    });

    describe('configuration', function () {
        it('returns configured timeout', function () {
            expect($this->service->getTimeout())->toBe(5);
        });

        it('returns allowed extensions', function () {
            $extensions = $this->service->getAllowedExtensions();

            expect($extensions)->toContain('sh')
                ->and($extensions)->toContain('py')
                ->and($extensions)->toContain('ts')
                ->and($extensions)->toContain('js');
        });

        it('returns blocked commands', function () {
            $blocked = $this->service->getBlockedCommands();

            expect($blocked)->toContain('rm -rf /')
                ->and($blocked)->toContain('sudo ');
        });

        it('can be disabled via config', function () {
            Config::set('laraclaw.script_execution.enabled', false);
            $service = new ScriptExecutionService($this->settings, $this->skillSearch);

            $result = $service->execute('test-skill', 'echo_test.sh');

            expect($result->success)->toBeFalse()
                ->and($result->error)->toContain('disabled');
        });
    });

    describe('timeout handling', function () {
        it('handles timeout', function () {
            // This test is slow, skip in CI if needed
            if (getenv('SKIP_SLOW_TESTS')) {
                test()->markTestSkipped('Skipping slow test');
            }

            Config::set('laraclaw.script_execution.timeout', 1);
            $service = new ScriptExecutionService($this->settings, $this->skillSearch);

            $result = $service->execute('test-skill', 'slow_script.sh');

            expect($result->success)->toBeFalse()
                ->and($result->error)->toContain('timed out')
                ->and($result->exitCode)->toBe(124);
        })->skip(getenv('SKIP_SLOW_TESTS'), 'Skipping slow test');
    });

    describe('ScriptExecutionResultDTO', function () {
        it('can be converted to array', function () {
            $result = ScriptExecutionResultDTO::success(
                output: 'Test output',
                duration: 1.5,
                scriptPath: '/path/to/script.sh',
                args: ['arg1', 'arg2']
            );

            $array = $result->toArray();

            expect($array['success'])->toBeTrue()
                ->and($array['output'])->toBe('Test output')
                ->and($array['duration'])->toBe(1.5)
                ->and($array['script_path'])->toBe('/path/to/script.sh')
                ->and($array['args'])->toBe(['arg1', 'arg2']);
        });

        it('can format output', function () {
            $success = ScriptExecutionResultDTO::success('Test output', 1.5);
            expect($success->getFormattedOutput())
                ->toContain('Test output')
                ->toContain('1.5s');

            $error = ScriptExecutionResultDTO::error('Something went wrong', 1);
            expect($error->getFormattedOutput())
                ->toContain('Error')
                ->toContain('Something went wrong');
        });
    });

    // ==========================================
    // Direct Command Execution Tests
    // ==========================================

    describe('direct command execution', function () {
        it('can execute a direct command', function () {
            $result = $this->service->executeDirectCommand('echo "Hello direct command"');

            expect($result->success)->toBeTrue()
                ->and($result->output)->toContain('Hello direct command')
                ->and($result->scriptPath)->toBe('direct');
        });

        it('can execute direct command with pipes', function () {
            $result = $this->service->executeDirectCommand('echo "test" | wc -c');

            expect($result->success)->toBeTrue()
                // "test\n" is 5 characters
                ->and($result->output)->toContain('5');
        });

        it('blocks direct command with sudo', function () {
            $result = $this->service->executeDirectCommand('sudo ls');

            expect($result->success)->toBeFalse()
                ->and(strtolower($result->error))->toContain('blocked')
                ->and($result->error)->toContain('sudo ')
                ->and($result->error)->toContain('sudo ls');
        });

        it('blocks direct command with dangerous rm', function () {
            $result = $this->service->executeDirectCommand('rm -rf /');

            expect($result->success)->toBeFalse()
                ->and(strtolower($result->error))->toContain('blocked')
                ->and($result->error)->toContain('rm -rf /');
        });

        it('includes blocked pattern in error message', function () {
            $result = $this->service->executeDirectCommand('chmod 777 /tmp/test');

            expect($result->success)->toBeFalse()
                ->and(strtolower($result->error))->toContain('blocked pattern')
                ->and($result->error)->toContain('chmod 777');
        });

        it('can check if command is blocked', function () {
            expect($this->service->isCommandBlocked('sudo ls'))->toBeTrue()
                ->and($this->service->isCommandBlocked('rm -rf /'))->toBeTrue()
                ->and($this->service->isCommandBlocked('chmod 777 file'))->toBeTrue()
                ->and($this->service->isCommandBlocked('ls -la'))->toBeFalse()
                ->and($this->service->isCommandBlocked('echo "hello"'))->toBeFalse();
        });

        it('can be disabled', function () {
            Config::set('laraclaw.script_execution.enabled', false);
            $service = new ScriptExecutionService($this->settings, $this->skillSearch);

            $result = $service->executeDirectCommand('echo "test"');

            expect($result->success)->toBeFalse()
                ->and($result->error)->toContain('disabled');
        });

        it('handles failure', function () {
            $result = $this->service->executeDirectCommand('ls /nonexistent_directory_12345');

            expect($result->success)->toBeFalse()
                ->and($result->error)->not->toBeEmpty();
        });

        it('uses custom working directory', function () {
            $customDir = $this->testWorkspace.'/custom_dir';
            File::makeDirectory($customDir, 0755, true);
            File::put($customDir.'/testfile.txt', 'test content');

            $result = $this->service->executeDirectCommand('cat testfile.txt', $customDir);

            expect($result->success)->toBeTrue()
                ->and($result->output)->toContain('test content');
        });
    });
});

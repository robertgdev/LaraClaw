<?php

declare(strict_types=1);

use App\Services\ResponseParserService;
use App\Services\ScriptExecutionService;
use App\Services\SettingsService;
use App\Services\SkillSearchService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    // Create test workspace with unique ID
    $this->testWorkspace = storage_path('app/laraclaw/test-parser-workspace-'.uniqid('', true));
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

    // Create test script
    $scriptPath = $this->testSkillDir.'/scripts/echo_test.sh';
    File::put($scriptPath, <<<'SH'
#!/bin/bash
echo "Hello from test script"
SH);
    chmod($scriptPath, 0755);

    // Mock settings
    $this->settings = Mockery::mock(SettingsService::class);
    $this->settings->shouldReceive('getWorkspacePath')->andReturn($this->testWorkspace);

    // Mock skill search
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
    $this->skillSearch->shouldReceive('getAllSkills')
        ->andReturn([
            'test-skill' => [
                'name' => 'test-skill',
                'directory' => $this->testSkillDir,
                'has_scripts' => true,
            ],
        ]);
    $this->skillSearch->shouldReceive('getSkillScripts')
        ->with('test-skill')
        ->andReturn([
            ['name' => 'echo_test.sh', 'path' => $this->testSkillDir.'/scripts/echo_test.sh'],
        ]);

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
            'mkfs',
            'dd if=',
            'curl | bash',
            'wget | bash',
        ],
    ]);

    $this->scriptExecutionService = new ScriptExecutionService($this->settings, $this->skillSearch);
    $this->parser = new ResponseParserService($this->scriptExecutionService);
});

afterEach(function () {
    // Clean up test workspace
    if (File::isDirectory($this->testWorkspace)) {
        File::deleteDirectory($this->testWorkspace);
    }

    Mockery::close();
});

describe('ResponseParserService', function () {
    describe('execute request detection', function () {
        it('detects direct commands in code blocks', function () {
            $response = 'I will check that.```execute: echo "hello"```';

            $hasExecute = $this->parser->hasExecuteRequests($response);

            expect($hasExecute)->toBeTrue();
        });

        it('detects direct commands in brackets', function () {
            $response = 'I will check that. [execute: echo "hello"]';

            $hasExecute = $this->parser->hasExecuteRequests($response);

            expect($hasExecute)->toBeTrue();
        });

        it('detects script paths in code blocks', function () {
            $response = '```execute: scripts/echo_test.sh```';

            $hasExecute = $this->parser->hasExecuteRequests($response);

            expect($hasExecute)->toBeTrue();
        });

        it('returns false when no execute requests', function () {
            $response = 'Just some regular text without execute blocks.';

            $hasExecute = $this->parser->hasExecuteRequests($response);

            expect($hasExecute)->toBeFalse();
        });
    });

    describe('direct command execution', function () {
        it('parses and executes direct commands', function () {
            $response = '```execute: echo "hello world"```';

            $result = $this->parser->parseAndExecute($response);

            expect($result->executions)->toHaveCount(1)
                ->and($result->executions[0]->success)->toBeTrue()
                ->and($result->executions[0]->output)->toContain('hello world');
        });

        it('handles blocked direct commands', function () {
            $response = '```execute: sudo ls```';

            $result = $this->parser->parseAndExecute($response);

            expect($result->executions)->toHaveCount(1)
                ->and($result->executions[0]->success)->toBeFalse()
                ->and(strtolower($result->executions[0]->error))->toContain('blocked')
                ->and($result->executions[0]->error)->toContain('sudo ls')
                ->and($result->executions[0]->error)->toContain('sudo ');
        });

        it('handles direct commands with pipes', function () {
            $response = '```execute: echo "test" | wc -c```';

            $result = $this->parser->parseAndExecute($response);

            expect($result->executions)->toHaveCount(1)
                ->and($result->executions[0]->success)->toBeTrue();
        });

        it('handles direct commands with quotes', function () {
            $response = '```execute: echo "hello world"```';

            $result = $this->parser->parseAndExecute($response);

            expect($result->executions[0]->success)->toBeTrue()
                ->and($result->modifiedResponse)->toContain('hello world');
        });
    });

    describe('script path execution', function () {
        it('executes skill scripts', function () {
            $response = '```execute: scripts/echo_test.sh```';

            $result = $this->parser->parseAndExecute($response);

            expect($result->executions)->toHaveCount(1)
                ->and($result->executions[0]->success)->toBeTrue()
                ->and($result->executions[0]->output)->toContain('Hello from test script');
        });
    });

    describe('mixed content handling', function () {
        it('handles multiple execute blocks', function () {
            $response = 'First: ```execute: echo "first"``` Second: ```execute: echo "second"```';

            $result = $this->parser->parseAndExecute($response);

            expect($result->executions)->toHaveCount(2)
                ->and($result->executions[0]->success)->toBeTrue()
                ->and($result->executions[1]->success)->toBeTrue();
        });

        it('preserves surrounding text', function () {
            $response = 'Before. ```execute: echo "hello"``` After.';

            $result = $this->parser->parseAndExecute($response);

            expect($result->modifiedResponse)->toContain('Before.')
                ->and($result->modifiedResponse)->toContain('After.')
                ->and($result->modifiedResponse)->toContain('hello');
        });

        it('handles bracket-style execute requests', function () {
            $response = 'Check this: [execute: echo "bracket test"]';

            $result = $this->parser->parseAndExecute($response);

            expect($result->executions)->toHaveCount(1)
                ->and($result->executions[0]->success)->toBeTrue();
        });
    });

    describe('error handling', function () {
        it('handles failed commands gracefully', function () {
            $response = '```execute: ls /nonexistent_directory_12345```';

            $result = $this->parser->parseAndExecute($response);

            expect($result->executions)->toHaveCount(1)
                ->and($result->executions[0]->success)->toBeFalse();
        });

        it('includes blocked pattern in error message', function () {
            $response = '```execute: chmod 777 /tmp/test```';

            $result = $this->parser->parseAndExecute($response);

            expect($result->executions[0]->success)->toBeFalse()
                ->and($result->executions[0]->error)->toContain('chmod 777');
        });
    });

    describe('isScriptPath helper (via ScriptPathResolver)', function () {
        it('correctly identifies script paths', function () {
            $resolver = new \App\Services\ResponseParser\ScriptPathResolver($this->skillSearch);

            expect($resolver->isScriptPath('scripts/test.sh'))->toBeTrue()
                ->and($resolver->isScriptPath('test-skill/scripts/test.sh'))->toBeTrue()
                ->and($resolver->isScriptPath('echo "hello"'))->toBeFalse()
                ->and($resolver->isScriptPath('curl wttr.in'))->toBeFalse();
        });
    });
});

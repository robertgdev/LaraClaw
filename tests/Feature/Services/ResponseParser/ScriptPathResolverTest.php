<?php

use App\Services\ResponseParser\ScriptPathResolver;
use App\Services\SkillSearchService;

describe('ScriptPathResolver', function () {
    beforeEach(function () {
        $this->skillSearch = Mockery::mock(SkillSearchService::class);
        $this->resolver = new ScriptPathResolver($this->skillSearch);
    });

    describe('isScriptPath', function () {
        it('detects scripts/ prefix', function () {
            expect($this->resolver->isScriptPath('scripts/schedule.sh'))->toBeTrue();
        });

        it('detects skill/scripts/ pattern', function () {
            expect($this->resolver->isScriptPath('schedule/scripts/schedule.sh'))->toBeTrue();
        });

        it('detects .agents/skills/ pattern', function () {
            expect($this->resolver->isScriptPath('.agents/skills/weather/scripts/weather.sh'))->toBeTrue();
        });

        it('returns false for direct commands', function () {
            expect($this->resolver->isScriptPath('curl "wttr.in/Berlin"'))->toBeFalse();
            expect($this->resolver->isScriptPath('echo hello'))->toBeFalse();
        });
    });

    describe('extractScriptInfo', function () {
        it('parses scripts/schedule.sh format', function () {
            $this->skillSearch->shouldReceive('getAllSkills')->andReturn(['schedule' => ['name' => 'schedule']]);

            $info = $this->resolver->extractScriptInfo('scripts/schedule.sh');

            expect($info)->not->toBeNull()
                ->and($info['skill'])->toBe('schedule')
                ->and($info['script'])->toBe('schedule.sh');
        });

        it('parses skill/scripts/script.sh format', function () {
            $info = $this->resolver->extractScriptInfo('schedule/scripts/schedule.sh');

            expect($info)->not->toBeNull()
                ->and($info['skill'])->toBe('schedule')
                ->and($info['script'])->toBe('schedule.sh');
        });

        it('parses .agents/skills/skill/scripts/script.sh format', function () {
            $info = $this->resolver->extractScriptInfo('.agents/skills/weather/scripts/weather.sh');

            expect($info)->not->toBeNull()
                ->and($info['skill'])->toBe('weather')
                ->and($info['script'])->toBe('weather.sh');
        });

        it('parses bare script name', function () {
            $this->skillSearch->shouldReceive('getAllSkills')->andReturn(['schedule' => ['name' => 'schedule']]);

            $info = $this->resolver->extractScriptInfo('schedule.sh');

            expect($info)->not->toBeNull()
                ->and($info['skill'])->toBe('schedule')
                ->and($info['script'])->toBe('schedule.sh');
        });

        it('returns null for invalid paths', function () {
            $info = $this->resolver->extractScriptInfo('');

            expect($info)->toBeNull();
        });
    });

    describe('parseCommand', function () {
        it('parses script path and arguments', function () {
            $result = $this->resolver->parseCommand('scripts/schedule.sh create --cron "0 9 * * *"');

            expect($result['script'])->toBe('scripts/schedule.sh')
                ->and($result['args'])->toContain('create')
                ->and($result['args'])->toContain('--cron')
                ->and($result['args'])->toContain('0 9 * * *');
        });

        it('handles empty command', function () {
            $result = $this->resolver->parseCommand('');

            expect($result['script'])->toBeNull()
                ->and($result['args'])->toBeEmpty();
        });
    });

    describe('tokenizeCommand', function () {
        it('respects quoted strings', function () {
            $tokens = $this->resolver->tokenizeCommand('schedule.sh --name "my task"');

            expect($tokens)->toBe(['schedule.sh', '--name', 'my task']);
        });

        it('handles single quotes', function () {
            $tokens = $this->resolver->tokenizeCommand("schedule.sh --cron '0 9 * * *'");

            expect($tokens)->toBe(['schedule.sh', '--cron', '0 9 * * *']);
        });

        it('handles multiple spaces', function () {
            $tokens = $this->resolver->tokenizeCommand('schedule.sh   create   task');

            expect($tokens)->toBe(['schedule.sh', 'create', 'task']);
        });
    });
});

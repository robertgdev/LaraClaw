<?php

use App\Services\ScriptExecution\CommandBuilder;

describe('CommandBuilder', function () {
    beforeEach(function () {
        $this->builder = new CommandBuilder;
    });

    describe('getInterpreter', function () {
        it('returns bash for .sh files', function () {
            expect($this->builder->getInterpreter('/path/to/script.sh'))->toBe('bash');
        });

        it('returns python3 for .py files', function () {
            expect($this->builder->getInterpreter('/path/to/script.py'))->toBe('python3');
        });

        it('returns npx ts-node for .ts files', function () {
            expect($this->builder->getInterpreter('/path/to/script.ts'))->toBe('npx ts-node');
        });

        it('returns node for .js files', function () {
            expect($this->builder->getInterpreter('/path/to/script.js'))->toBe('node');
        });

        it('returns null for unknown extensions', function () {
            expect($this->builder->getInterpreter('/path/to/script.rb'))->toBeNull()
                ->and($this->builder->getInterpreter('/path/to/script.exe'))->toBeNull();
        });
    });

    describe('build', function () {
        it('builds command with interpreter', function () {
            $command = $this->builder->build('/path/to/script.py', ['arg1', 'arg2']);

            expect($command)->toContain('python3')
                ->and($command)->toContain('/path/to/script.py')
                ->and($command)->toContain('arg1')
                ->and($command)->toContain('arg2');
        });

        it('escapes arguments', function () {
            $command = $this->builder->build('/path/to/script.py', ['hello world', 'test;rm -rf /']);

            // Arguments should be shell-escaped
            expect($command)->toContain("'hello world'")
                ->and($command)->toContain("'test;rm -rf /'");
        });

        it('handles empty arguments', function () {
            $command = $this->builder->build('/path/to/script.py', []);

            expect($command)->toContain('python3')
                ->and($command)->toContain('/path/to/script.py');
        });
    });
});

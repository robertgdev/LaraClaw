<?php

use App\Services\ScriptExecution\CommandSecurityGuard;

describe('CommandSecurityGuard', function () {
    describe('default blocked commands', function () {
        beforeEach(function () {
            $this->guard = new CommandSecurityGuard;
        });

        it('blocks rm -rf /', function () {
            expect($this->guard->isBlocked('rm -rf /'))->toBeTrue();
        });

        it('blocks sudo commands', function () {
            expect($this->guard->isBlocked('sudo ls'))->toBeTrue();
        });

        it('blocks chmod 777', function () {
            expect($this->guard->isBlocked('chmod 777 /tmp/test'))->toBeTrue();
        });

        it('blocks fork bombs', function () {
            expect($this->guard->isBlocked(':(){ :|:& };:'))->toBeTrue();
        });

        it('blocks curl piped to bash', function () {
            expect($this->guard->isBlocked('curl | bash'))->toBeTrue()
                ->and($this->guard->isBlocked('wget | bash'))->toBeTrue();
        });

        it('allows safe commands', function () {
            expect($this->guard->isBlocked('ls -la'))->toBeFalse()
                ->and($this->guard->isBlocked('echo "hello"'))->toBeFalse()
                ->and($this->guard->isBlocked('cat file.txt'))->toBeFalse();
        });

        it('is case-insensitive', function () {
            expect($this->guard->isBlocked('SUDO ls'))->toBeTrue()
                ->and($this->guard->isBlocked('RM -RF /'))->toBeTrue();
        });
    });

    describe('custom blocked commands', function () {
        it('uses custom blocklist', function () {
            $guard = new CommandSecurityGuard(['danger', 'evil']);

            expect($guard->isBlocked('danger zone'))->toBeTrue()
                ->and($guard->isBlocked('evil command'))->toBeTrue()
                ->and($guard->isBlocked('sudo ls'))->toBeFalse(); // not in custom list
        });
    });

    describe('findBlockedPattern', function () {
        it('returns the matched pattern', function () {
            $guard = new CommandSecurityGuard;

            expect($guard->findBlockedPattern('sudo ls'))->toBe('sudo ')
                ->and($guard->findBlockedPattern('rm -rf /'))->toBe('rm -rf /');
        });

        it('returns null for safe commands', function () {
            $guard = new CommandSecurityGuard;

            expect($guard->findBlockedPattern('echo hello'))->toBeNull();
        });
    });

    describe('getBlockedCommands', function () {
        it('returns the blocklist', function () {
            $guard = new CommandSecurityGuard(['a', 'b']);

            expect($guard->getBlockedCommands())->toBe(['a', 'b']);
        });
    });
});

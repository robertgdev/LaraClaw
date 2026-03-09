<?php

use App\Models\Conversation;
use App\Services\Chat\ChatShellCommandHandler;
use App\Services\Chat\ChatShellRenderer;
use App\Services\SessionService;
use App\Services\SettingsService;
use App\Services\Shell\ShellHistoryManager;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->settings = app(SettingsService::class);
    $this->sessionService = app(SessionService::class);
    $this->renderer = new ChatShellRenderer($this->settings);
    $this->handler = new ChatShellCommandHandler($this->settings, $this->sessionService, $this->renderer);

    $buffered = new BufferedOutput;
    $this->output = new OutputStyle(new ArrayInput([]), $buffered);
    $this->buffered = $buffered;
});

describe('ChatShellCommandHandler', function () {
    describe('exit commands', function () {
        it('returns exit for /exit', function () {
            $agentId = 'default';
            $teamId = null;
            $shouldReset = false;
            $session = null;
            $historyManager = Mockery::mock(ShellHistoryManager::class);

            $result = $this->handler->handle(
                '/exit', $this->output,
                $agentId, $teamId, $shouldReset, $session, 'user-1', $historyManager
            );

            expect($result)->toBe('exit');
        });

        it('returns exit for /quit', function () {
            $agentId = 'default';
            $teamId = null;
            $shouldReset = false;
            $session = null;
            $historyManager = Mockery::mock(ShellHistoryManager::class);

            $result = $this->handler->handle(
                '/quit', $this->output,
                $agentId, $teamId, $shouldReset, $session, 'user-1', $historyManager
            );

            expect($result)->toBe('exit');
        });
    });

    describe('help command', function () {
        it('returns null for /help', function () {
            $agentId = 'default';
            $teamId = null;
            $shouldReset = false;
            $session = null;
            $historyManager = Mockery::mock(ShellHistoryManager::class);

            $result = $this->handler->handle(
                '/help', $this->output,
                $agentId, $teamId, $shouldReset, $session, 'user-1', $historyManager
            );

            expect($result)->toBeNull();
            expect($this->buffered->fetch())->toContain('Available Commands');
        });
    });

    describe('reset command', function () {
        it('sets shouldReset flag', function () {
            $agentId = 'default';
            $teamId = null;
            $shouldReset = false;
            $session = null;
            $historyManager = Mockery::mock(ShellHistoryManager::class);

            $this->handler->handle(
                '/reset', $this->output,
                $agentId, $teamId, $shouldReset, $session, 'user-1', $historyManager
            );

            expect($shouldReset)->toBeTrue();
        });
    });

    describe('unknown command', function () {
        it('outputs error for unknown command', function () {
            $agentId = 'default';
            $teamId = null;
            $shouldReset = false;
            $session = null;
            $historyManager = Mockery::mock(ShellHistoryManager::class);

            $result = $this->handler->handle(
                '/nonexistent', $this->output,
                $agentId, $teamId, $shouldReset, $session, 'user-1', $historyManager
            );

            expect($result)->toBeNull();
            expect($this->buffered->fetch())->toContain('Unknown command');
        });
    });

    describe('new session command', function () {
        it('creates new session and sets shouldReset', function () {
            $agentId = 'default';
            $teamId = null;
            $shouldReset = false;
            $session = null;
            $historyManager = Mockery::mock(ShellHistoryManager::class);

            $this->handler->handle(
                '/new', $this->output,
                $agentId, $teamId, $shouldReset, $session, 'user-1', $historyManager
            );

            expect($shouldReset)->toBeTrue();
            expect($session)->toBeInstanceOf(Conversation::class);
            expect($session->is_active)->toBeTrue();
        });
    });

    describe('clear command', function () {
        it('returns null for /clear', function () {
            $agentId = 'default';
            $teamId = null;
            $shouldReset = false;
            $session = null;
            $historyManager = Mockery::mock(ShellHistoryManager::class);

            $result = $this->handler->handle(
                '/clear', $this->output,
                $agentId, $teamId, $shouldReset, $session, 'user-1', $historyManager
            );

            expect($result)->toBeNull();
        });
    });
});

<?php

use App\Services\Commands\ChannelCommandHandler;
use App\Services\Commands\SlashCommandHandler;
use App\Services\ConversationHistoryService;
use App\Services\SettingsService;

beforeEach(function () {
    $settings = app(SettingsService::class);
    $chatHistory = app(ConversationHistoryService::class);
    $slashHandler = new SlashCommandHandler($settings, $chatHistory);
    $this->handler = new ChannelCommandHandler($slashHandler);
});

describe('ChannelCommandHandler (extracted)', function () {
    describe('handle method', function () {
        it('processes /agents channel command', function () {
            $result = $this->handler->handle('/agents');
            expect($result)->not->toBeNull()
                ->and($result->type)->toBe('agents');
        });

        it('processes !agents bang command', function () {
            $result = $this->handler->handle('!agents');
            expect($result)->not->toBeNull()
                ->and($result->type)->toBe('agents');
        });

        it('processes /teams channel command', function () {
            $result = $this->handler->handle('/teams');
            expect($result)->not->toBeNull()
                ->and($result->type)->toBe('teams');
        });

        it('returns null for plain text messages', function () {
            $result = $this->handler->handle('Hello world');
            expect($result)->toBeNull();
        });

        it('returns null for unrecognized commands', function () {
            $result = $this->handler->handle('/unknown');
            expect($result)->toBeNull();
        });
    });

    describe('isChannelCommand method', function () {
        it('identifies /agents as recognized channel command', function () {
            expect($this->handler->isChannelCommand('/agents'))->toBeTrue();
        });

        it('identifies !teams as recognized channel command', function () {
            expect($this->handler->isChannelCommand('!teams'))->toBeTrue();
        });

        it('rejects non-command text', function () {
            expect($this->handler->isChannelCommand('Hello'))->toBeFalse();
        });
    });
});

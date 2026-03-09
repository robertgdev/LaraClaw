<?php

use App\Services\Commands\SlashCommandHandler;
use App\Services\ConversationHistoryService;
use App\Services\SettingsService;

beforeEach(function () {
    $this->settings = app(SettingsService::class);
    $this->chatHistory = app(ConversationHistoryService::class);
    $this->handler = new SlashCommandHandler($this->settings, $this->chatHistory);
});

describe('SlashCommandHandler', function () {
    describe('handle', function () {
        it('handles /agents command', function () {
            $result = $this->handler->handle('/agents');
            expect($result->type)->toBe('agents')
                ->and($result->success)->toBeTrue();
        });

        it('handles /teams command', function () {
            $result = $this->handler->handle('/teams');
            expect($result->type)->toBe('teams')
                ->and($result->success)->toBeTrue();
        });

        it('handles /ping command', function () {
            $result = $this->handler->handle('/ping');
            expect($result->type)->toBe('pong');
        });

        it('handles /help command', function () {
            $result = $this->handler->handle('/help');
            expect($result->type)->toBe('help');
        });

        it('handles /history command', function () {
            $result = $this->handler->handle('/history');
            expect($result->type)->toBe('history')
                ->and($result->success)->toBeTrue();
        });

        it('handles unknown commands', function () {
            $result = $this->handler->handle('/unknown');
            expect($result->success)->toBeFalse();
        });

        it('handles /reset without args', function () {
            $result = $this->handler->handle('/reset');
            expect($result->type)->toBe('reset_usage');
        });
    });
});

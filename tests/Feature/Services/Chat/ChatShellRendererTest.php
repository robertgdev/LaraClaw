<?php

use App\Services\Chat\ChatShellRenderer;
use App\Services\SettingsService;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    $this->settings = app(SettingsService::class);
    $this->renderer = new ChatShellRenderer($this->settings);

    $this->buffered = new BufferedOutput;
    $this->output = new OutputStyle(new ArrayInput([]), $this->buffered);
});

describe('ChatShellRenderer', function () {
    describe('showHelp', function () {
        it('renders help text with available commands', function () {
            $this->renderer->showHelp($this->output);

            $output = $this->buffered->fetch();
            expect($output)
                ->toContain('Available Commands')
                ->toContain('/help')
                ->toContain('/agent')
                ->toContain('/teams')
                ->toContain('/exit')
                ->toContain('Message Routing')
                ->toContain('Session Commands');
        });
    });

    describe('displayResponse', function () {
        it('renders agent response with duration', function () {
            $this->renderer->displayResponse($this->output, 'Hello world!', 1.23);

            $output = $this->buffered->fetch();
            expect($output)
                ->toContain('Response:')
                ->toContain('Hello world!')
                ->toContain('1.23s');
        });

        it('word-wraps long responses', function () {
            $longResponse = str_repeat('word ', 100);
            $this->renderer->displayResponse($this->output, $longResponse, 0.5);

            $output = $this->buffered->fetch();
            expect($output)->toContain('word');
        });
    });

    describe('displayError', function () {
        it('renders error message', function () {
            $this->renderer->displayError($this->output, 'Something went wrong');

            $output = $this->buffered->fetch();
            expect($output)
                ->toContain('Something went wrong')
                ->toContain('Check the logs');
        });
    });

    describe('showCurrentSession', function () {
        it('renders no-session message when null', function () {
            $this->renderer->showCurrentSession($this->output, null);

            $output = $this->buffered->fetch();
            expect($output)->toContain('No active session');
        });
    });

    describe('listSessions', function () {
        it('renders empty state', function () {
            $this->renderer->listSessions($this->output, collect());

            $output = $this->buffered->fetch();
            expect($output)
                ->toContain('Your Sessions')
                ->toContain('No sessions found');
        });
    });

    describe('stripAnsi', function () {
        it('removes ANSI color codes from string', function () {
            $colored = "\e[32mGreen\e[0m \e[31mRed\e[0m";
            $result = $this->renderer->stripAnsi($colored);

            expect($result)->toBe('Green Red');
        });

        it('returns plain string unchanged', function () {
            $plain = 'Hello World';
            $result = $this->renderer->stripAnsi($plain);

            expect($result)->toBe('Hello World');
        });
    });
});

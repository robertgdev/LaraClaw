<?php

use App\Services\ResponseParser\ExecuteBlockDetector;

describe('ExecuteBlockDetector', function () {
    beforeEach(function () {
        $this->detector = new ExecuteBlockDetector;
    });

    it('detects code block execute requests', function () {
        $response = 'Here is the result: ```execute: scripts/schedule.sh create```';

        expect($this->detector->hasExecuteRequests($response))->toBeTrue();
    });

    it('detects bracket execute requests', function () {
        $response = 'Running [execute: scripts/schedule.sh list]';

        expect($this->detector->hasExecuteRequests($response))->toBeTrue();
    });

    it('detects bare execute requests', function () {
        $response = "Let me do that:\nexecute: scripts/schedule.sh list";

        expect($this->detector->hasExecuteRequests($response))->toBeTrue();
    });

    it('returns false when no execute requests present', function () {
        $response = 'This is a normal response without any execute blocks.';

        expect($this->detector->hasExecuteRequests($response))->toBeFalse();
    });

    it('extracts all execute requests', function () {
        $response = '```execute: scripts/schedule.sh create``` and [execute: scripts/weather.sh forecast]';

        $requests = $this->detector->extractAll($response);

        expect($requests)->toHaveCount(2);
    });

    it('provides detection details', function () {
        $response = '```execute: scripts/schedule.sh create```';

        $details = $this->detector->getDetectionDetails($response);

        expect($details['code_block'])->toBeTrue()
            ->and($details['bracket'])->toBeFalse()
            ->and($details['bare'])->toBeFalse();
    });

    it('replaces execute blocks using callback', function () {
        $response = 'Before ```execute: scripts/test.sh``` After';

        $result = $this->detector->replaceAll($response, function ($command, $format) {
            return "[REPLACED: {$command}]";
        });

        expect($result)->toContain('[REPLACED: scripts/test.sh]')
            ->and($result)->not->toContain('```execute:');
    });

    it('replaces multiple formats correctly', function () {
        $response = '```execute: scripts/a.sh``` and [execute: scripts/b.sh]';

        $commands = [];
        $this->detector->replaceAll($response, function ($command, $format) use (&$commands) {
            $commands[] = ['command' => $command, 'format' => $format];

            return 'DONE';
        });

        expect($commands)->toHaveCount(2);
    });
});

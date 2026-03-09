<?php

use App\Services\Discovery\SkillsFindOutputParser;

describe('SkillsFindOutputParser', function () {
    beforeEach(function () {
        $this->parser = new SkillsFindOutputParser;
    });

    describe('parse', function () {
        it('parses standard output', function () {
            $output = <<<'TXT'
laraclaw/skills@imagegen    4.6K installs
└ https://skills.sh/laraclaw/skills/imagegen

other/repo@imagegen-pro    1.2K installs
└ https://skills.sh/other/repo/imagegen-pro
TXT;

            $results = $this->parser->parse($output);

            expect($results)->toHaveCount(2)
                ->and($results[0]['name'])->toBe('imagegen')
                ->and($results[0]['owner'])->toBe('laraclaw')
                ->and($results[0]['repo'])->toBe('skills')
                ->and($results[0]['installs'])->toBe(4600)
                ->and($results[1]['name'])->toBe('imagegen-pro')
                ->and($results[1]['owner'])->toBe('other')
                ->and($results[1]['installs'])->toBe(1200);
        });

        it('handles empty output', function () {
            expect($this->parser->parse(''))->toBe([]);
        });

        it('handles banner lines', function () {
            $output = <<<'TXT'
████████ Skills Registry
Install with: npx skills add owner/repo@skill
laraclaw/skills@weather    2K installs
TXT;

            $results = $this->parser->parse($output);

            expect($results)->toHaveCount(1)
                ->and($results[0]['name'])->toBe('weather');
        });

        it('handles lines without install counts', function () {
            $output = "laraclaw/skills@test\n└ https://skills.sh/laraclaw/skills/test";

            $results = $this->parser->parse($output);

            expect($results)->toHaveCount(1)
                ->and($results[0]['installs'])->toBe(0);
        });
    });

    describe('parseInstallsCount', function () {
        it('parses K suffix', function () {
            expect($this->parser->parseInstallsCount('4.6K'))->toBe(4600);
        });

        it('parses M suffix', function () {
            expect($this->parser->parseInstallsCount('1.2M'))->toBe(1200000);
        });

        it('parses plain numbers', function () {
            expect($this->parser->parseInstallsCount('500'))->toBe(500);
        });

        it('is case-insensitive', function () {
            expect($this->parser->parseInstallsCount('4.6k'))->toBe(4600);
        });
    });
});

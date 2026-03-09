<?php

use App\Services\Skills\SignatureGenerator;

describe('SignatureGenerator', function () {
    beforeEach(function () {
        $this->generator = new SignatureGenerator;
    });

    describe('generate', function () {
        it('generates consistent signatures regardless of order', function () {
            $sig1 = $this->generator->generate(['image', 'generate', 'sunset']);
            $sig2 = $this->generator->generate(['sunset', 'generate', 'image']);

            expect($sig1)->toBe($sig2);
        });

        it('generates consistent signatures regardless of case', function () {
            $sig1 = $this->generator->generate(['Image', 'GENERATE', 'Sunset']);
            $sig2 = $this->generator->generate(['image', 'generate', 'sunset']);

            expect($sig1)->toBe($sig2);
        });

        it('generates different signatures for different keywords', function () {
            $sig1 = $this->generator->generate(['image', 'generate']);
            $sig2 = $this->generator->generate(['schedule', 'meeting']);

            expect($sig1)->not->toBe($sig2);
        });

        it('returns valid md5 hash', function () {
            $sig = $this->generator->generate(['test', 'keywords']);

            expect(strlen($sig))->toBe(32)
                ->and(ctype_xdigit($sig))->toBeTrue();
        });
    });

    describe('normalize', function () {
        it('lowercases keywords', function () {
            $normalized = $this->generator->normalize(['IMAGE', 'Generate', 'SUNSET']);

            expect($normalized)->toBe(['generate', 'image', 'sunset']);
        });

        it('sorts keywords', function () {
            $normalized = $this->generator->normalize(['z', 'a', 'm']);

            expect($normalized)->toBe(['a', 'm', 'z']);
        });

        it('limits to max keywords', function () {
            $generator = new SignatureGenerator(3);
            $normalized = $generator->normalize(['d', 'c', 'b', 'a', 'e']);

            expect($normalized)->toHaveCount(3)
                ->and($normalized)->toBe(['a', 'b', 'c']);
        });

        it('defaults to 10 max keywords', function () {
            $keywords = range('a', 'z'); // 26 keywords
            $normalized = $this->generator->normalize($keywords);

            expect(count($normalized))->toBe(10);
        });
    });
});

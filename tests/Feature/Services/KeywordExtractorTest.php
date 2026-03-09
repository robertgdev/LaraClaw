<?php

use App\Services\KeywordExtractor;

describe('KeywordExtractor', function () {
    it('extracts keywords from text', function () {
        $keywords = KeywordExtractor::extract('Generate an image of a sunset over mountains');

        expect($keywords)->toBeArray()
            ->and($keywords)->toContain('generate')
            ->and($keywords)->toContain('image')
            ->and($keywords)->toContain('sunset')
            ->and($keywords)->toContain('mountains');
    });

    it('filters out stop words', function () {
        $keywords = KeywordExtractor::extract('the quick brown fox jumps over the lazy dog');

        expect($keywords)->not->toContain('the')
            ->and($keywords)->not->toContain('very')
            ->and($keywords)->toContain('quick')
            ->and($keywords)->toContain('brown')
            ->and($keywords)->toContain('fox');
    });

    it('filters words shorter than 3 characters', function () {
        $keywords = KeywordExtractor::extract('a big cat on a mat');

        expect($keywords)->not->toContain('a')
            ->and($keywords)->not->toContain('on')
            ->and($keywords)->toContain('big')
            ->and($keywords)->toContain('cat')
            ->and($keywords)->toContain('mat');
    });

    it('limits results to max parameter', function () {
        $keywords = KeywordExtractor::extract(
            'one two three four five six seven eight nine ten eleven twelve thirteen',
            5
        );

        expect($keywords)->toHaveCount(5);
    });

    it('normalizes text to lowercase', function () {
        $keywords = KeywordExtractor::extract('HELLO World Schedule');

        expect($keywords)->toContain('hello')
            ->and($keywords)->toContain('world')
            ->and($keywords)->toContain('schedule');
    });

    it('removes non-alphanumeric characters', function () {
        $keywords = KeywordExtractor::extract('hello-world, foo+bar! test@email.com');

        expect($keywords)->toContain('hello')
            ->and($keywords)->toContain('world')
            ->and($keywords)->toContain('foo')
            ->and($keywords)->toContain('bar');
    });

    it('orders by frequency', function () {
        $keywords = KeywordExtractor::extract('schedule schedule schedule image image weather');

        expect($keywords[0])->toBe('schedule')
            ->and($keywords[1])->toBe('image')
            ->and($keywords[2])->toBe('weather');
    });

    it('returns empty array for empty input', function () {
        $keywords = KeywordExtractor::extract('');

        expect($keywords)->toBeEmpty();
    });

    it('accepts custom stop words', function () {
        $keywords = KeywordExtractor::extract('custom stop words test', 20, ['custom', 'stop', 'words']);

        expect($keywords)->not->toContain('custom')
            ->and($keywords)->not->toContain('stop')
            ->and($keywords)->toContain('test');
    });

    it('returns default stop words including config values', function () {
        $stopWords = KeywordExtractor::getStopWords();

        expect($stopWords)->toContain('the')
            ->and($stopWords)->toContain('and')
            ->and($stopWords)->toContain('for');
    });
});

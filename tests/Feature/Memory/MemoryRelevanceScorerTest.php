<?php

use App\Services\Memory\MemoryRelevanceScorer;

beforeEach(function () {
    $this->scorer = new MemoryRelevanceScorer;
});

describe('MemoryRelevanceScorer', function () {
    describe('computeTemporalScore', function () {
        it('returns high score for recently accessed memories', function () {
            $now = time() * 1000;
            $justNow = $now - 1000; // 1 second ago

            $score = $this->scorer->computeTemporalScore($justNow, 0, $now);

            expect($score)->toBeGreaterThan(0.99);
        });

        it('returns lower score for old memories', function () {
            $now = time() * 1000;
            $thirtyDaysAgo = $now - (30 * 86400 * 1000);

            $score = $this->scorer->computeTemporalScore($thirtyDaysAgo, 0, $now);

            expect($score)->toBeLessThan(0.5);
        });

        it('boosts score based on access count', function () {
            $now = time() * 1000;
            $sevenDaysAgo = $now - (7 * 86400 * 1000);

            $scoreNoAccess = $this->scorer->computeTemporalScore($sevenDaysAgo, 0, $now);
            $scoreHighAccess = $this->scorer->computeTemporalScore($sevenDaysAgo, 50, $now);

            expect($scoreHighAccess)->toBeGreaterThan($scoreNoAccess);
        });

        it('caps score at 1.0', function () {
            $now = time() * 1000;

            $score = $this->scorer->computeTemporalScore($now, 1000, $now);

            expect($score)->toBeLessThanOrEqual(1.0);
        });
    });

    describe('normalizeScore', function () {
        it('normalizes score relative to max', function () {
            expect($this->scorer->normalizeScore(5.0, 10.0))->toBe(0.5)
                ->and($this->scorer->normalizeScore(10.0, 10.0))->toBe(1.0)
                ->and($this->scorer->normalizeScore(0.0, 10.0))->toBe(0.0);
        });

        it('handles zero max gracefully', function () {
            expect($this->scorer->normalizeScore(5.0, 0.0))->toBe(0.0);
        });

        it('caps at 1.0 for values exceeding max', function () {
            expect($this->scorer->normalizeScore(15.0, 10.0))->toBe(1.0);
        });
    });

    describe('contentSimilarity', function () {
        it('returns 1.0 for identical strings', function () {
            $score = $this->scorer->contentSimilarity(
                'User prefers dark mode',
                'User prefers dark mode'
            );

            expect($score)->toBe(1.0);
        });

        it('returns high similarity for overlapping content', function () {
            $score = $this->scorer->contentSimilarity(
                'User prefers dark mode for code editors',
                'User prefers dark mode for code editors and terminals'
            );

            expect($score)->toBeGreaterThan(0.6);
        });

        it('returns low similarity for unrelated content', function () {
            $score = $this->scorer->contentSimilarity(
                'User lives in Philippines',
                'Always use TypeScript strict mode'
            );

            expect($score)->toBeLessThan(0.3);
        });

        it('returns 0.0 for empty strings', function () {
            expect($this->scorer->contentSimilarity('', ''))->toBe(0.0)
                ->and($this->scorer->contentSimilarity('hello', ''))->toBe(0.0);
        });
    });

    describe('tokenize', function () {
        it('splits text into lowercase tokens', function () {
            $tokens = array_values($this->scorer->tokenize('Hello World Test'));

            expect($tokens)->toContain('hello')
                ->and($tokens)->toContain('world')
                ->and($tokens)->toContain('test');
        });

        it('removes short tokens (1 char)', function () {
            $tokens = array_values($this->scorer->tokenize('I am a test person'));

            expect($tokens)->not->toContain('i')
                ->and($tokens)->not->toContain('a');
        });

        it('removes special characters', function () {
            $tokens = array_values($this->scorer->tokenize('hello@world.com test!'));

            expect($tokens)->toContain('hello')
                ->and($tokens)->toContain('world')
                ->and($tokens)->toContain('test');
        });
    });

    describe('score', function () {
        it('combines fts, temporal, and importance scores', function () {
            $now = time() * 1000;

            $result = $this->scorer->score(
                rawFtsScore: 8.0,
                maxFtsScore: 10.0,
                lastAccessedAtMs: $now - 1000,
                accessCount: 5,
                importance: 0.9,
                nowMs: $now
            );

            // Combined score should be between 0 and ~1.x
            expect($result)->toBeGreaterThan(0.0)
                ->and($result)->toBeLessThanOrEqual(2.0);
        });

        it('weighs importance correctly', function () {
            $now = time() * 1000;

            $highImportance = $this->scorer->score(
                rawFtsScore: 5.0,
                maxFtsScore: 10.0,
                lastAccessedAtMs: $now,
                accessCount: 0,
                importance: 1.0,
                nowMs: $now
            );

            $lowImportance = $this->scorer->score(
                rawFtsScore: 5.0,
                maxFtsScore: 10.0,
                lastAccessedAtMs: $now,
                accessCount: 0,
                importance: 0.1,
                nowMs: $now
            );

            expect($highImportance)->toBeGreaterThan($lowImportance);
        });
    });

    describe('getWeight', function () {
        it('returns default weights', function () {
            expect($this->scorer->getWeight('fts'))->toBe(0.35)
                ->and($this->scorer->getWeight('temporal'))->toBe(0.25)
                ->and($this->scorer->getWeight('importance'))->toBe(0.20)
                ->and($this->scorer->getWeight('feedback'))->toBe(0.25);
        });
    });
});

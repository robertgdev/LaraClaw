<?php

declare(strict_types=1);

namespace App\Services;

use function Safe\preg_replace;

/**
 * Extracts and normalizes keywords from text input.
 *
 * Shared keyword extraction logic used by SkillSearchService
 * and IntentClassificationService to avoid duplicated implementations.
 */
class KeywordExtractor
{
    /**
     * Default stop words filtered from keyword extraction.
     *
     * @var array<int, string>
     */
    protected static array $defaultStopWords = [
        'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
        'of', 'with', 'by', 'from', 'is', 'are', 'was', 'were', 'be', 'been',
        'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
        'could', 'should', 'may', 'might', 'must', 'shall', 'can', 'this',
        'that', 'these', 'those', 'i', 'you', 'he', 'she', 'it', 'we', 'they',
        'what', 'which', 'who', 'when', 'where', 'why', 'how', 'all', 'each',
        'every', 'both', 'few', 'more', 'most', 'other', 'some', 'such', 'no',
        'nor', 'not', 'only', 'own', 'same', 'so', 'than', 'too', 'very',
    ];

    /**
     * Extract keywords from text.
     *
     * Normalizes text to lowercase, removes non-alphanumeric characters,
     * filters stop words, and returns the top keywords by frequency.
     *
     * @param  string  $text  The text to extract keywords from
     * @param  int  $max  Maximum number of keywords to return
     * @param  array<int, string>|null  $stopWords  Custom stop words (null uses defaults)
     * @return array<int, string>
     */
    public static function extract(string $text, int $max = 20, ?array $stopWords = null): array
    {
        $stopWords ??= static::getStopWords();

        // Normalize text
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);

        // Tokenize
        $words = explode(' ', $text);
        $words = array_filter($words, fn ($w) => strlen($w) > 2);

        // Remove stop words
        $words = array_diff($words, $stopWords);

        // Count frequency
        $frequency = array_count_values($words);
        arsort($frequency);

        // Return top keywords
        return array_slice(array_keys($frequency), 0, $max);
    }

    /**
     * Get the default stop words list.
     *
     * Merges the built-in stop words with any configured via
     * 'laraclaw.intent_classification.ignore_words'.
     *
     * @return array<int, string>
     */
    public static function getStopWords(): array
    {
        $configWords = config('laraclaw.intent_classification.ignore_words', []);

        if (! empty($configWords)) {
            return array_unique(array_merge(static::$defaultStopWords, $configWords));
        }

        return static::$defaultStopWords;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Skills;

/**
 * Generates normalized intent signatures from keyword arrays.
 *
 * Keywords are lowercased, sorted, and limited to 10 before hashing
 * with MD5 to produce a consistent, order-independent signature.
 */
class SignatureGenerator
{
    protected int $maxKeywords;

    public function __construct(int $maxKeywords = 10)
    {
        $this->maxKeywords = $maxKeywords;
    }

    /**
     * Generate a normalized signature from keywords.
     *
     * @param  array<int, string>  $keywords
     */
    public function generate(array $keywords): string
    {
        $normalized = $this->normalize($keywords);

        return md5(implode('|', $normalized));
    }

    /**
     * Normalize keywords: lowercase, sort, limit.
     *
     * @param  array<int, string>  $keywords
     * @return array<int, string>
     */
    public function normalize(array $keywords): array
    {
        $normalized = array_map('strtolower', $keywords);
        sort($normalized);

        return array_slice($normalized, 0, $this->maxKeywords);
    }
}

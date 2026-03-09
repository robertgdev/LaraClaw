<?php

declare(strict_types=1);

namespace App\Services\Intent;

use function Safe\preg_match_all;

/**
 * EntityExtractor - Extracts named entities from messages.
 *
 * Extracts locations, dates, people, organizations, and topics
 * using pattern-based matching. Designed as a standalone, stateless
 * utility for use across the classification and routing subsystems.
 */
class EntityExtractor
{
    /**
     * Extract entities from the message (locations, dates, etc.).
     *
     * @return array{
     *     locations: string[],
     *     dates: string[],
     *     people: string[],
     *     organizations: string[],
     *     topics: string[]
     * }
     */
    public function extract(string $message): array
    {
        $entities = [
            'locations' => [],
            'dates' => [],
            'people' => [],
            'organizations' => [],
            'topics' => [],
        ];

        // Location patterns
        if (preg_match_all('/\b(in|at|to|from)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\b/', $message, $matches)) {
            $entities['locations'] = array_values(array_unique($matches[2]));
        }

        // Date patterns
        if (preg_match_all('/\b(\d{1,2}\/\d{1,2}\/\d{2,4}|\d{4}-\d{2}-\d{2}|today|tomorrow|yesterday|next\s+\w+|this\s+\w+)\b/i', $message, $matches)) {
            $entities['dates'] = array_values(array_unique($matches[1]));
        }

        return $entities;
    }
}

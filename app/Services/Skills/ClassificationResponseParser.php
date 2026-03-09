<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\Logging\MultiLogger;

use function Safe\json_decode;

/**
 * Parses LLM responses for skill classification.
 *
 * Extracts JSON arrays from LLM text output, validates required fields,
 * and normalizes the data structure for storage.
 */
class ClassificationResponseParser
{
    /**
     * Parse the LLM classification response.
     *
     * @param  string  $response  The raw LLM response
     * @param  int|null  $skillId  The skill ID to associate with mappings
     * @return array Array of parsed mappings
     */
    public function parse(string $response, ?int $skillId = null): array
    {
        // Try to extract JSON array from response
        if (! preg_match('/\[[\s\S]*\]/s', $response, $match)) {
            MultiLogger::warning('No JSON array found in LLM response', [
                'response_preview' => substr($response, 0, 500),
            ]);

            return [];
        }

        try {
            $decoded = json_decode($match[0], true);
        } catch (\Exception $e) {
            MultiLogger::error('Failed to parse JSON from LLM response', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        // Validate and normalize each mapping
        $mappings = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }

            // Required fields
            if (empty($item['sample_intent'])) {
                continue;
            }

            $mappings[] = [
                'sample_intent' => $item['sample_intent'],
                'keywords' => $item['keywords'] ?? [],
                'skill_id' => $skillId,
                'confidence' => (float) ($item['confidence'] ?? 0.8),
                'category' => $item['category'] ?? 'unknown',
            ];
        }

        return $mappings;
    }
}

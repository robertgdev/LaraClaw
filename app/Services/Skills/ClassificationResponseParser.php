<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\DTOs\IntentMappingDTO;
use App\Logging\MultiLogger;
use App\TypedCollections\IntentMappingDTOCollection;

use function Safe\json_decode;
use function Safe\preg_match;

/**
 * Parses LLM responses for skill classification.
 *
 * Extracts JSON arrays from LLM text output, validates required fields,
 * and normalizes the data structure for storage.
 */
class ClassificationResponseParser
{
    public function parse(string $response, ?int $skillId = null): IntentMappingDTOCollection
    {
        $mappings = new IntentMappingDTOCollection;

        // Try to extract JSON array from response
        if (! preg_match('/\[[\s\S]*\]/s', $response, $match)) {
            MultiLogger::warning('No JSON array found in LLM response', [
                'response_preview' => substr($response, 0, 500),
            ]);

            return $mappings;
        }

        try {
            $decoded = json_decode($match[0], true);
        } catch (\Exception $e) {
            MultiLogger::error('Failed to parse JSON from LLM response', [
                'error' => $e->getMessage(),
            ]);

            return $mappings;
        }

        if (! is_array($decoded)) {
            return $mappings;
        }

        // Validate and normalize each mapping
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }

            // Required fields
            if (empty($item['sample_intent'])) {
                continue;
            }

            $mappings->add(
                IntentMappingDTO::fromArray([
                    'sampleIntent' => $item['sample_intent'],
                    'keywords' => $item['keywords'] ?? [],
                    'confidence' => (float) ($item['confidence'] ?? 0.8),
                    'category' => $item['category'] ?? 'unknown',
                ], $skillId)
            );
        }

        return $mappings;
    }
}

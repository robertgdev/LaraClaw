<?php

use App\Services\Skills\ClassificationResponseParser;
use App\TypedCollections\IntentMappingDTOCollection;

beforeEach(function () {
    $this->parser = new ClassificationResponseParser;
});

describe('ClassificationResponseParser', function () {
    describe('parse', function () {
        it('parses valid JSON array response', function () {
            $response = <<<'JSON'
Here are the skill mappings:
[
  {
    "sample_intent": "Generate an image of a sunset",
    "keywords": ["generate", "image", "sunset"],
    "confidence": 0.95,
    "category": "creative"
  },
  {
    "sample_intent": "Schedule a meeting for tomorrow",
    "keywords": ["schedule", "meeting", "tomorrow"],
    "confidence": 0.90,
    "category": "scheduling"
  }
]
JSON;

            $result = $this->parser->parse($response, 42);

            expect($result)->toBeInstanceOf(IntentMappingDTOCollection::class)
                ->toHaveCount(2)
                ->and($result[0]->sampleIntent)->toBe('Generate an image of a sunset')
                ->and($result[0]->skillId)->toBe(42)
                ->and($result[0]->confidence)->toBe(0.95)
                ->and($result[1]->skillId)->toBe(42);
        });

        it('returns empty collection for invalid JSON', function () {
            $result = $this->parser->parse('This is not valid JSON at all');

            expect($result)->toBeInstanceOf(IntentMappingDTOCollection::class)
                ->toBeEmpty();
        });

        it('returns empty collection for JSON without array', function () {
            $result = $this->parser->parse('{"not": "an array"}');

            expect($result)->toBeInstanceOf(IntentMappingDTOCollection::class)
                ->toBeEmpty();
        });

        it('filters entries missing sample_intent', function () {
            $response = <<<'JSON'
[
  {"sample_intent": "Valid entry", "keywords": ["valid"], "confidence": 0.9},
  {"keywords": ["no intent"], "confidence": 0.7}
]
JSON;

            $result = $this->parser->parse($response);

            expect($result)->toHaveCount(1)
                ->and($result[0]->sampleIntent)->toBe('Valid entry');
        });

        it('applies defaults for missing optional fields', function () {
            $response = '[{"sample_intent": "Minimal entry"}]';

            $result = $this->parser->parse($response);

            expect($result)->toHaveCount(1)
                ->and($result[0]->keywords)->toBe([])
                ->and($result[0]->confidence)->toBe(0.8)
                ->and($result[0]->category)->toBe('unknown')
                ->and($result[0]->skillId)->toBeNull();
        });

        it('passes skill_id to all mappings', function () {
            $response = '[{"sample_intent": "Test 1"}, {"sample_intent": "Test 2"}]';

            $result = $this->parser->parse($response, 99);

            expect($result[0]->skillId)->toBe(99)
                ->and($result[1]->skillId)->toBe(99);
        });

        it('extracts JSON from surrounding text', function () {
            $response = "Here's the classification:\n\n[{\"sample_intent\": \"Test\"}]\n\nDone!";

            $result = $this->parser->parse($response);

            expect($result)->toHaveCount(1);
        });
    });
});

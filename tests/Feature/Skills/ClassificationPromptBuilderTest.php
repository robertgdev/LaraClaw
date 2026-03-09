<?php

use App\Services\Skills\ClassificationPromptBuilder;

beforeEach(function () {
    $this->builder = new ClassificationPromptBuilder;
});

describe('ClassificationPromptBuilder', function () {
    describe('buildSingleSkillPrompt', function () {
        it('includes skill name and description', function () {
            $result = $this->builder->buildSingleSkillPrompt('imagegen', [
                'description' => 'Generate images using AI',
                'keywords' => ['image', 'generate', 'ai'],
            ]);

            expect($result)->toContain('imagegen')
                ->toContain('Generate images using AI')
                ->toContain('image, generate, ai');
        });

        it('requests JSON array format', function () {
            $result = $this->builder->buildSingleSkillPrompt('test', [
                'description' => 'Test skill',
            ]);

            expect($result)->toContain('JSON')
                ->toContain('sample_intent')
                ->toContain('keywords')
                ->toContain('confidence')
                ->toContain('category');
        });

        it('includes configurable intents count', function () {
            $this->builder->setIntentsPerSkill(10);

            $result = $this->builder->buildSingleSkillPrompt('test', [
                'description' => 'Test',
            ]);

            expect($result)->toContain('10');
        });

        it('truncates long descriptions', function () {
            $longDesc = str_repeat('This is very long. ', 100);

            $result = $this->builder->buildSingleSkillPrompt('test', [
                'description' => $longDesc,
            ]);

            expect(strlen($result))->toBeLessThan(strlen($longDesc));
        });

        it('handles missing keywords gracefully', function () {
            $result = $this->builder->buildSingleSkillPrompt('minimal', [
                'description' => 'Minimal skill',
            ]);

            expect($result)->toBeString()->toContain('minimal');
        });

        it('limits keywords to 5', function () {
            $result = $this->builder->buildSingleSkillPrompt('test', [
                'description' => 'Test',
                'keywords' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'],
            ]);

            // Should only contain first 5 keywords
            expect($result)->toContain('a, b, c, d, e')
                ->not->toContain('f, g, h');
        });
    });

    describe('buildSkillDetails', function () {
        it('extracts intents and keywords from mappings', function () {
            $mappings = [
                ['sample_intent' => 'Generate an image', 'keywords' => ['generate', 'image']],
                ['sample_intent' => 'Create a picture', 'keywords' => ['create', 'picture']],
            ];

            $result = $this->builder->buildSkillDetails($mappings);

            expect($result['intents'])->toHaveCount(2)
                ->toContain('Generate an image')
                ->toContain('Create a picture')
                ->and($result['keywords'])->toHaveCount(4);
        });

        it('deduplicates keywords', function () {
            $mappings = [
                ['sample_intent' => 'Intent 1', 'keywords' => ['shared', 'unique1']],
                ['sample_intent' => 'Intent 2', 'keywords' => ['shared', 'unique2']],
            ];

            $result = $this->builder->buildSkillDetails($mappings);

            expect($result['keywords'])->toHaveCount(3);
        });

        it('handles empty mappings', function () {
            $result = $this->builder->buildSkillDetails([]);

            expect($result['intents'])->toBeEmpty()
                ->and($result['keywords'])->toBeEmpty();
        });
    });

    describe('setIntentsPerSkill / getIntentsPerSkill', function () {
        it('sets and gets intents per skill count', function () {
            $result = $this->builder->setIntentsPerSkill(10);

            expect($result)->toBeInstanceOf(ClassificationPromptBuilder::class)
                ->and($this->builder->getIntentsPerSkill())->toBe(10);
        });
    });
});

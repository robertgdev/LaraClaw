<?php

use App\Services\Intent\LlmIntentClassifier;
use App\Services\SettingsService;

describe('LlmIntentClassifier', function () {
    beforeEach(function () {
        $this->settings = app(SettingsService::class);
        $this->classifier = new LlmIntentClassifier($this->settings);
    });

    describe('parseResponse', function () {
        it('parses valid JSON with all fields', function () {
            $text = '{"intent": "coding", "confidence": 0.95, "matched_skill": "code-review", "skill_confidence": 0.8, "entities": {"locations": [], "dates": []}, "suggested_agent": "coder", "reasoning": "code keywords"}';

            $result = $this->classifier->parseResponse($text, 'debug this', ['debug']);

            expect($result->intent)->toBe('coding')
                ->and($result->confidence)->toBe(0.95)
                ->and($result->matchedSkill)->toBe('code-review')
                ->and($result->skillConfidence)->toBe(0.8)
                ->and($result->suggestedAgent)->toBe('coder')
                ->and($result->reasoning)->toBe('code keywords')
                ->and($result->method)->toBe('llm');
        });

        it('parses JSON with minimal fields', function () {
            $text = '{"intent": "question", "confidence": 0.7}';

            $result = $this->classifier->parseResponse($text, 'what is x?', ['what']);

            expect($result->intent)->toBe('question')
                ->and($result->confidence)->toBe(0.7)
                ->and($result->matchedSkill)->toBeNull()
                ->and($result->method)->toBe('llm');
        });

        it('returns unknown for invalid intent category', function () {
            $text = '{"intent": "not_a_real_intent", "confidence": 0.9}';

            $result = $this->classifier->parseResponse($text, 'test', ['test']);

            expect($result->intent)->toBe('unknown')
                ->and($result->confidence)->toBe(0.3);
        });

        it('handles malformed JSON gracefully', function () {
            $result = $this->classifier->parseResponse('not json at all', 'test', ['test']);

            expect($result->intent)->toBe('unknown')
                ->and($result->method)->toBe('fallback');
        });

        it('extracts JSON from surrounding text', function () {
            $text = 'Here is my analysis: {"intent": "coding", "confidence": 0.85} and more text';

            $result = $this->classifier->parseResponse($text, 'test', ['test']);

            expect($result->intent)->toBe('coding')
                ->and($result->confidence)->toBe(0.85);
        });

        it('preserves keywords in result', function () {
            $keywords = ['debug', 'code', 'python'];
            $text = '{"intent": "coding", "confidence": 0.9}';

            $result = $this->classifier->parseResponse($text, 'debug python code', $keywords);

            expect($result->keywords)->toBe($keywords);
        });
    });

    describe('buildPrompt', function () {
        it('builds a valid prompt string', function () {
            $prompt = $this->classifier->buildPrompt(
                'Debug this code',
                'question, coding, creative',
                'code-review, imagegen',
                ['debug', 'code']
            );

            expect($prompt)->toContain('Debug this code')
                ->and($prompt)->toContain('question, coding, creative')
                ->and($prompt)->toContain('code-review, imagegen')
                ->and($prompt)->toContain('debug, code');
        });
    });

    describe('getClassificationModel', function () {
        it('returns fast model for known providers', function () {
            expect($this->classifier->getClassificationModel('openai', 'gpt-4'))
                ->toBe('gpt-4o-mini');

            expect($this->classifier->getClassificationModel('anthropic', 'claude-3-opus'))
                ->toBe('claude-3-5-haiku-20241022');
        });

        it('returns default model for unknown providers', function () {
            expect($this->classifier->getClassificationModel('unknown', 'custom-model'))
                ->toBe('custom-model');
        });
    });
});

<?php

use App\DTOs\IntentClassificationDTO;
use App\Services\Discovery\SkillGapDetector;

describe('SkillGapDetector', function () {
    beforeEach(function () {
        $this->detector = new SkillGapDetector(0.5);
    });

    describe('isSkillRequired', function () {
        it('returns false when skill match confidence is high', function () {
            $classification = new IntentClassificationDTO(
                intent: 'chat',
                confidence: 0.9,
                matchedSkill: 'existing-skill',
                skillConfidence: 0.9,
                entities: [],
                keywords: []
            );

            expect($this->detector->isSkillRequired('Hello there', $classification))->toBeFalse();
        });

        it('returns true for action-oriented intents', function () {
            $classification = new IntentClassificationDTO(
                intent: 'generate',
                confidence: 0.8,
                matchedSkill: null,
                skillConfidence: 0.2,
                entities: [],
                keywords: []
            );

            expect($this->detector->isSkillRequired('Generate something', $classification))->toBeTrue();
        });

        it('returns true for messages containing action verbs', function () {
            $classification = new IntentClassificationDTO(
                intent: 'question',
                confidence: 0.3,
                matchedSkill: null,
                skillConfidence: 0.0,
                entities: [],
                keywords: []
            );

            expect($this->detector->isSkillRequired('Can you schedule a meeting?', $classification))->toBeTrue();
        });

        it('returns false for non-action messages', function () {
            $classification = new IntentClassificationDTO(
                intent: 'question',
                confidence: 0.3,
                matchedSkill: null,
                skillConfidence: 0.0,
                entities: [],
                keywords: []
            );

            expect($this->detector->isSkillRequired('What is the weather?', $classification))->toBeFalse();
        });
    });

    describe('extractSearchTerm', function () {
        it('uses keywords from classification', function () {
            $classification = new IntentClassificationDTO(
                intent: 'generate',
                confidence: 0.8,
                keywords: ['image', 'sunset', 'beautiful']
            );

            $term = $this->detector->extractSearchTerm('Generate a beautiful sunset image', $classification);

            expect($term)->toBe('image sunset beautiful');
        });

        it('extracts from common patterns', function () {
            $classification = new IntentClassificationDTO(
                intent: 'unknown',
                confidence: 0.1,
                keywords: []
            );

            $term = $this->detector->extractSearchTerm('generate a report', $classification);

            expect($term)->toBe('report');
        });

        it('falls back to intent', function () {
            $classification = new IntentClassificationDTO(
                intent: 'automation',
                confidence: 0.5,
                keywords: []
            );

            $term = $this->detector->extractSearchTerm('Do something complex', $classification);

            expect($term)->toBe('automation');
        });
    });

    describe('extractNouns', function () {
        it('extracts words of 4+ chars excluding stop words', function () {
            $nouns = $this->detector->extractNouns('I want to have something done about this project');

            expect($nouns)->not->toContain('want')
                ->and($nouns)->not->toContain('have')
                ->and($nouns)->not->toContain('about')
                ->and($nouns)->not->toContain('this')
                ->and($nouns)->toContain('something');
        });

        it('limits to 2 nouns', function () {
            $nouns = $this->detector->extractNouns('Something interesting beautiful wonderful amazing project');
            $parts = explode(' ', $nouns);

            expect(count($parts))->toBeLessThanOrEqual(2);
        });
    });
});

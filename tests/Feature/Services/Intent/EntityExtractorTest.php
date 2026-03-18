<?php

use App\Services\Intent\EntityExtractor;

describe('EntityExtractor', function () {
    beforeEach(function () {
        $this->extractor = new EntityExtractor;
    });

    it('extracts location entities', function () {
        $result = $this->extractor->extract('What is the population in Berlin?');

        expect($result)->toBeInstanceOf(\App\DTOs\ExtractedEntitiesDTO::class)
            ->and($result->locations)->toBeArray()
            ->and($result->dates)->toBeArray()
            ->and($result->people)->toBeArray()
            ->and($result->organizations)->toBeArray()
            ->and($result->topics)->toBeArray();
    });

    it('extracts date entities', function () {
        $result = $this->extractor->extract('Schedule a meeting for tomorrow at 3pm');

        expect($result->dates)->not()->toBeEmpty();
    });

    it('extracts multiple date formats', function () {
        $result = $this->extractor->extract('Compare data from 2024-01-15 and 01/20/2024');

        expect($result->dates)->toHaveCount(2);
    });

    it('returns empty arrays when no entities found', function () {
        $result = $this->extractor->extract('hello world');

        expect($result->locations)->toBeEmpty()
            ->and($result->dates)->toBeEmpty()
            ->and($result->people)->toBeEmpty();
    });

    it('extracts locations with prepositions', function () {
        $result = $this->extractor->extract('I traveled from London to Paris');

        expect($result->locations)->toContain('London')
            ->and($result->locations)->toContain('Paris');
    });
});

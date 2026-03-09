<?php

use App\Services\Skills\ApiErrorClassifier;

beforeEach(function () {
    $this->classifier = new ApiErrorClassifier;
});

describe('ApiErrorClassifier', function () {
    describe('classify', function () {
        it('classifies connection timeout errors', function () {
            expect($this->classifier->classify('cURL error 28: Connection timed out'))
                ->toContain('Connection timeout');

            expect($this->classifier->classify('The request timed out'))
                ->toContain('Connection timeout');
        });

        it('classifies DNS resolution errors', function () {
            expect($this->classifier->classify('cURL error 6: Could not resolve host'))
                ->toContain('DNS resolution failed');
        });

        it('classifies connection failed errors', function () {
            expect($this->classifier->classify('cURL error 7: Failed to connect'))
                ->toContain('Connection failed');
        });

        it('classifies authentication errors', function () {
            expect($this->classifier->classify('401 Unauthorized'))
                ->toContain('Authentication failed');

            expect($this->classifier->classify('HTTP 401'))
                ->toContain('Authentication failed');
        });

        it('classifies rate limit errors', function () {
            expect($this->classifier->classify('429 Too Many Requests'))
                ->toContain('Rate limited');
        });

        it('classifies server errors', function () {
            expect($this->classifier->classify('500 Internal Server Error'))
                ->toContain('Server error');
        });

        it('returns generic message for unknown errors', function () {
            expect($this->classifier->classify('Something totally unexpected happened'))
                ->toBe('API request failed');
        });
    });
});

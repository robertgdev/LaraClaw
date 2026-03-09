<?php

declare(strict_types=1);

namespace App\Services\Skills;

/**
 * Classifies API error messages into user-friendly descriptions.
 *
 * Reusable across any service that communicates with external LLM APIs.
 * Handles common error patterns: timeouts, DNS failures, auth errors,
 * rate limiting, and server errors.
 */
class ApiErrorClassifier
{
    /**
     * Classify an error message into a human-readable description.
     *
     * @param  string  $errorMessage  The raw error message
     * @return string A user-friendly error description
     */
    public function classify(string $errorMessage): string
    {
        if (str_contains($errorMessage, 'cURL error 28') || str_contains($errorMessage, 'timed out')) {
            return 'Connection timeout - the API server did not respond in time. Check your network connection or try again later.';
        }

        if (str_contains($errorMessage, 'cURL error 6') || str_contains($errorMessage, 'Could not resolve host')) {
            return 'DNS resolution failed - check your internet connection';
        }

        if (str_contains($errorMessage, 'cURL error 7') || str_contains($errorMessage, 'Failed to connect')) {
            return 'Connection failed - the API server is unreachable';
        }

        if (str_contains($errorMessage, '401') || str_contains($errorMessage, 'Unauthorized')) {
            return 'Authentication failed - check your API key';
        }

        if (str_contains($errorMessage, '429') || str_contains($errorMessage, 'Too Many Requests')) {
            return 'Rate limited - too many requests, please wait and try again';
        }

        if (str_contains($errorMessage, '500') || str_contains($errorMessage, 'Internal Server Error')) {
            return 'Server error - the API provider is experiencing issues';
        }

        return 'API request failed';
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ResponseParser;

use function Safe\preg_match;
use function Safe\preg_match_all;
use function Safe\preg_replace_callback;

/**
 * Detects and extracts execute request blocks from AI responses.
 *
 * Supports multiple formats:
 * - Code blocks: ```execute: script_path args```
 * - Bracket notation: [execute: script_path args]
 * - Multi-line code blocks: ```execute: script_path\nargs```
 * - Bare format: execute: scripts/script_name.sh args
 */
class ExecuteBlockDetector
{
    /**
     * Pattern to detect script execution requests in code blocks.
     * Format: ```execute: script_path args```
     */
    public const CODE_BLOCK_PATTERN = '/```execute:\s*([^\n]+?)\s*\n?```/s';

    /**
     * Pattern to detect script execution requests in brackets.
     * Format: [execute: script_path args]
     */
    public const BRACKET_PATTERN = '/\[execute:\s*([^\]]+)\]/';

    /**
     * Pattern to detect multi-line script execution requests.
     * Format: ```execute: script_path\nargs line 1\nargs line 2```
     */
    public const MULTILINE_PATTERN = '/```execute:\s*([^\n]+)\n(.+?)```/s';

    /**
     * Pattern to detect bare execute requests (without code blocks or brackets).
     * Format: execute: scripts/script_name.sh args
     */
    public const BARE_PATTERN = '/^\s*execute:\s*(scripts\/[^\n]+)$/im';

    /**
     * Check if a response contains any execute requests.
     */
    public function hasExecuteRequests(string $response): bool
    {
        return preg_match(self::CODE_BLOCK_PATTERN, $response) === 1
            || preg_match(self::BRACKET_PATTERN, $response) === 1
            || preg_match(self::MULTILINE_PATTERN, $response) === 1
            || preg_match(self::BARE_PATTERN, $response) === 1;
    }

    /**
     * Alias for hasExecuteRequests().
     */
    public function hasExecuteBlocks(string $response): bool
    {
        return $this->hasExecuteRequests($response);
    }

    /**
     * Get detection details for debugging.
     *
     * @return array{code_block: bool, bracket: bool, multiline: bool, bare: bool}
     */
    public function getDetectionDetails(string $response): array
    {
        return [
            'code_block' => preg_match(self::CODE_BLOCK_PATTERN, $response) === 1,
            'bracket' => preg_match(self::BRACKET_PATTERN, $response) === 1,
            'multiline' => preg_match(self::MULTILINE_PATTERN, $response) === 1,
            'bare' => preg_match(self::BARE_PATTERN, $response) === 1,
        ];
    }

    /**
     * Extract all execute request commands from a response without replacing them.
     *
     * @return array<int, array{command: string, format: string, full_match: string}>
     */
    public function extractAll(string $response): array
    {
        $requests = [];

        // Multi-line first (more specific)
        preg_match_all(self::MULTILINE_PATTERN, $response, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $requests[] = [
                'command' => trim($match[1]).' '.trim($match[2]),
                'format' => 'multiline',
                'full_match' => $match[0],
            ];
        }

        // Code blocks
        preg_match_all(self::CODE_BLOCK_PATTERN, $response, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $requests[] = [
                'command' => trim($match[1]),
                'format' => 'code_block',
                'full_match' => $match[0],
            ];
        }

        // Brackets
        preg_match_all(self::BRACKET_PATTERN, $response, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $requests[] = [
                'command' => trim($match[1]),
                'format' => 'bracket',
                'full_match' => $match[0],
            ];
        }

        // Bare
        preg_match_all(self::BARE_PATTERN, $response, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $requests[] = [
                'command' => trim($match[1]),
                'format' => 'bare',
                'full_match' => $match[0],
            ];
        }

        return $requests;
    }

    /**
     * Replace execute blocks in a response using a callback.
     *
     * Processes patterns in priority order: multiline → code block → bracket → bare.
     * The callback receives the command string and should return the replacement text.
     *
     * @param  string  $response  The response text
     * @param  callable(string $command, string $format): string  $callback  Replacement callback
     * @return string The response with execute blocks replaced
     */
    public function replaceAll(string $response, callable $callback): string
    {
        // Multi-line first (more specific pattern)
        $response = preg_replace_callback(
            self::MULTILINE_PATTERN,
            fn ($m) => $callback(trim($m[1]).' '.trim($m[2]), 'multiline'),
            $response
        );

        // Single-line code blocks
        $response = preg_replace_callback(
            self::CODE_BLOCK_PATTERN,
            fn ($m) => $callback(trim($m[1]), 'code_block'),
            $response
        );

        // Bracket-style
        $response = preg_replace_callback(
            self::BRACKET_PATTERN,
            fn ($m) => $callback(trim($m[1]), 'bracket'),
            $response
        );

        // Bare execute requests
        $response = preg_replace_callback(
            self::BARE_PATTERN,
            fn ($m) => $callback(trim($m[1]), 'bare'),
            $response
        );

        return $response;
    }
}

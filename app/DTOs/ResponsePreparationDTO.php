<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents a prepared response with message and files.
 *
 * Used by ResponseDeliveryService to return prepared response data
 * after processing (file collection, long response handling).
 */
final readonly class ResponsePreparationDTO
{
    /**
     * @param string $message The prepared message text
     * @param array<string> $files List of file paths to attach
     */
    public function __construct(
        public string $message,
        public array $files = [],
    ) {}

    /**
     * Check if there are any files attached.
     */
    public function hasFiles(): bool
    {
        return ! empty($this->files);
    }
}

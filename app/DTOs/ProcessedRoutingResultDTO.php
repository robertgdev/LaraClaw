<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents the result of processing a message with routing.
 *
 * Used by CommandProcessingService::processWithRouting() to return
 * both the response and routing information.
 */
final readonly class ProcessedRoutingResultDTO
{
    public function __construct(
        public CommandResponseDTO $response,
        public ?RoutingResultDTO $routing,
    ) {}

    /**
     * Check if routing was performed.
     */
    public function hasRouting(): bool
    {
        return $this->routing !== null;
    }

    /**
     * Check if the response was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->response->success;
    }
}

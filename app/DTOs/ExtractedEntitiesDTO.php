<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents entities extracted from a message.
 *
 * Contains locations, dates, people, organizations, and topics
 * identified through pattern-based matching.
 */
final readonly class ExtractedEntitiesDTO
{
    /**
     * @param array<string> $locations
     * @param array<string> $dates
     * @param array<string> $people
     * @param array<string> $organizations
     * @param array<string> $topics
     */
    public function __construct(
        public array $locations = [],
        public array $dates = [],
        public array $people = [],
        public array $organizations = [],
        public array $topics = [],
    ) {}

    /**
     * Check if any entities were extracted.
     */
    public function hasAny(): bool
    {
        return ! empty($this->locations)
            || ! empty($this->dates)
            || ! empty($this->people)
            || ! empty($this->organizations)
            || ! empty($this->topics);
    }

    /**
     * Get total count of all entities.
     */
    public function totalCount(): int
    {
        return count($this->locations)
            + count($this->dates)
            + count($this->people)
            + count($this->organizations)
            + count($this->topics);
    }
}

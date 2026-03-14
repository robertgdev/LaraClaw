<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\EpisodicEventTypeEnum;

/**
 * Represents an episodic event to be recorded in memory.
 *
 * Used as parameter for MemoryEngineService::recordEvent() to provide
 * structured event data instead of an array.
 */
final readonly class EpisodicEventDTO
{
    /**
     * @param EpisodicEventTypeEnum|string $type The event type
     * @param string $content The event content
     * @param string|null $outcome The event outcome (optional)
     * @param float|null $importance Importance score (0.0 to 1.0)
     * @param string|null $agentId The agent that triggered this event
     */
    public function __construct(
        public EpisodicEventTypeEnum|string $type,
        public string $content,
        public ?string $outcome = null,
        public ?float $importance = null,
        public ?string $agentId = null,
    ) {}

    /**
     * Create a task completed event.
     */
    public static function taskCompleted(
        string $content,
        ?string $outcome = null,
        ?string $agentId = null
    ): self {
        return new self(
            type: EpisodicEventTypeEnum::TASK_COMPLETED,
            content: $content,
            outcome: $outcome,
            agentId: $agentId
        );
    }

    /**
     * Create a user feedback event.
     */
    public static function userFeedback(
        string $content,
        ?string $outcome = null
    ): self {
        return new self(
            type: EpisodicEventTypeEnum::CORRECTION,
            content: $content,
            outcome: $outcome
        );
    }

    /**
     * Get the event type as an enum.
     */
    public function getEventType(): EpisodicEventTypeEnum
    {
        return $this->type instanceof EpisodicEventTypeEnum
            ? $this->type
            : EpisodicEventTypeEnum::from($this->type);
    }
}

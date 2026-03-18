<?php

namespace App\DTOs;

/**
 * Data Transfer Object for compaction decision.
 *
 * Represents whether compaction should be triggered for a conversation.
 */
readonly class CompactionDecisionDTO
{
    public function __construct(
        public bool $shouldCompact,
        public string $reason,
        public int $currentTokens,
        public int $threshold,
    ) {}

    /**
     * Create a decision indicating no compaction needed.
     */
    public static function none(int $currentTokens, int $threshold): self
    {
        return new self(
            shouldCompact: false,
            reason: 'none',
            currentTokens: $currentTokens,
            threshold: $threshold,
        );
    }

    /**
     * Create a decision indicating threshold-based compaction.
     */
    public static function threshold(int $currentTokens, int $threshold): self
    {
        return new self(
            shouldCompact: true,
            reason: 'threshold',
            currentTokens: $currentTokens,
            threshold: $threshold,
        );
    }

    /**
     * Create a decision indicating manual compaction.
     */
    public static function manual(int $currentTokens, int $threshold): self
    {
        return new self(
            shouldCompact: true,
            reason: 'manual',
            currentTokens: $currentTokens,
            threshold: $threshold,
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, bool|int|string>
     */
    public function toArray(): array
    {
        return [
            'should_compact' => $this->shouldCompact,
            'reason' => $this->reason,
            'current_tokens' => $this->currentTokens,
            'threshold' => $this->threshold,
        ];
    }
}

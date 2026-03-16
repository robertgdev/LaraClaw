<?php

namespace App\DTOs;

/**
 * Data Transfer Object for compaction result.
 *
 * Represents the outcome of a compaction operation.
 */
readonly class CompactionResultDTO
{
    public function __construct(
        public bool $actionTaken,
        public int $tokensBefore,
        public int $tokensAfter,
        public ?string $createdSummaryId = null,
        public bool $condensed = false,
        public ?string $level = null,
    ) {}

    /**
     * Create a result indicating no action was taken.
     */
    public static function noAction(int $tokens): self
    {
        return new self(
            actionTaken: false,
            tokensBefore: $tokens,
            tokensAfter: $tokens,
        );
    }

    /**
     * Create a result for a leaf compaction.
     */
    public static function leaf(
        int $tokensBefore,
        int $tokensAfter,
        string $summaryId,
        string $level = 'normal'
    ): self {
        return new self(
            actionTaken: true,
            tokensBefore: $tokensBefore,
            tokensAfter: $tokensAfter,
            createdSummaryId: $summaryId,
            condensed: false,
            level: $level,
        );
    }

    /**
     * Create a result for a condensed compaction.
     */
    public static function condensed(
        int $tokensBefore,
        int $tokensAfter,
        string $summaryId,
        string $level = 'normal'
    ): self {
        return new self(
            actionTaken: true,
            tokensBefore: $tokensBefore,
            tokensAfter: $tokensAfter,
            createdSummaryId: $summaryId,
            condensed: true,
            level: $level,
        );
    }

    /**
     * Get the token reduction achieved.
     */
    public function getTokenReduction(): int
    {
        return max(0, $this->tokensBefore - $this->tokensAfter);
    }

    /**
     * Get the compression ratio achieved.
     */
    public function getCompressionRatio(): float
    {
        if ($this->tokensBefore === 0) {
            return 0.0;
        }

        return $this->getTokenReduction() / $this->tokensBefore;
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'action_taken' => $this->actionTaken,
            'tokens_before' => $this->tokensBefore,
            'tokens_after' => $this->tokensAfter,
            'created_summary_id' => $this->createdSummaryId,
            'condensed' => $this->condensed,
            'level' => $this->level,
            'token_reduction' => $this->getTokenReduction(),
            'compression_ratio' => $this->getCompressionRatio(),
        ];
    }
}

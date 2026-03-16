<?php

namespace App\DTOs;

use Carbon\CarbonInterface;

/**
 * Data Transfer Object for a summary record.
 *
 * Represents a summary with all its metadata.
 */
readonly class SummaryRecordDTO
{
    public function __construct(
        public string $summaryId,
        public int $conversationId,
        public string $kind,
        public int $depth,
        public string $content,
        public int $tokenCount,
        public array $fileIds,
        public ?CarbonInterface $earliestAt,
        public ?CarbonInterface $latestAt,
        public int $descendantCount,
        public int $descendantTokenCount,
        public int $sourceMessageTokenCount,
        public CarbonInterface $createdAt,
    ) {}

    /**
     * Create from model.
     */
    public static function fromModel(\App\Models\Summary $summary): self
    {
        return new self(
            summaryId: $summary->summary_id,
            conversationId: $summary->conversation_id,
            kind: $summary->kind,
            depth: $summary->depth,
            content: $summary->content,
            tokenCount: $summary->token_count,
            fileIds: $summary->file_ids ?? [],
            earliestAt: $summary->earliest_at,
            latestAt: $summary->latest_at,
            descendantCount: $summary->descendant_count,
            descendantTokenCount: $summary->descendant_token_count,
            sourceMessageTokenCount: $summary->source_message_token_count,
            createdAt: $summary->created_at,
        );
    }

    /**
     * Check if this is a leaf summary.
     */
    public function isLeaf(): bool
    {
        return $this->kind === 'leaf';
    }

    /**
     * Check if this is a condensed summary.
     */
    public function isCondensed(): bool
    {
        return $this->kind === 'condensed';
    }

    /**
     * Get the compression ratio.
     */
    public function getCompressionRatio(): float
    {
        if ($this->tokenCount === 0) {
            return 0.0;
        }

        return $this->sourceMessageTokenCount / $this->tokenCount;
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'summary_id' => $this->summaryId,
            'conversation_id' => $this->conversationId,
            'kind' => $this->kind,
            'depth' => $this->depth,
            'content' => $this->content,
            'token_count' => $this->tokenCount,
            'file_ids' => $this->fileIds,
            'earliest_at' => $this->earliestAt?->toIso8601String(),
            'latest_at' => $this->latestAt?->toIso8601String(),
            'descendant_count' => $this->descendantCount,
            'descendant_token_count' => $this->descendantTokenCount,
            'source_message_token_count' => $this->sourceMessageTokenCount,
            'created_at' => $this->createdAt->toIso8601String(),
        ];
    }
}

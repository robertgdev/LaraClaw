<?php

namespace App\DTOs;

use Carbon\CarbonInterface;

/**
 * Data Transfer Object for a context item.
 *
 * Represents an item in the ordered context list.
 */
readonly class ContextItemDTO
{
    public function __construct(
        public int $conversationId,
        public int $ordinal,
        public string $itemType,
        public ?int $messageId,
        public ?string $summaryId,
        public CarbonInterface $createdAt,
    ) {}

    /**
     * Create from model.
     */
    public static function fromModel(\App\Models\ContextItem $item): self
    {
        return new self(
            conversationId: $item->conversation_id,
            ordinal: $item->ordinal,
            itemType: $item->item_type,
            messageId: $item->message_id,
            summaryId: $item->summary_id,
            createdAt: $item->created_at,
        );
    }

    /**
     * Check if this is a message item.
     */
    public function isMessage(): bool
    {
        return $this->itemType === 'message';
    }

    /**
     * Check if this is a summary item.
     */
    public function isSummary(): bool
    {
        return $this->itemType === 'summary';
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'ordinal' => $this->ordinal,
            'item_type' => $this->itemType,
            'message_id' => $this->messageId,
            'summary_id' => $this->summaryId,
            'created_at' => $this->createdAt->toIso8601String(),
        ];
    }
}

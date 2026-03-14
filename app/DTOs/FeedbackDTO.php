<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\FeedbackEnum;

/**
 * Represents user feedback for a message or conversation.
 *
 * Used for the 3-set feedback system (thumbs up, thumbs down, neutral).
 * Can be applied to both individual messages and entire conversations.
 */
final readonly class FeedbackDTO
{
    /**
     * @param string $targetId The ID of the message or conversation receiving feedback
     * @param string $targetType Either 'message' or 'conversation'
     * @param FeedbackEnum $feedback The feedback value (positive, negative, neutral)
     * @param string|null $comment Optional user comment explaining the feedback
     * @param string|null $senderId The user who provided the feedback
     */
    public function __construct(
        public string $targetId,
        public string $targetType,
        public FeedbackEnum $feedback,
        public ?string $comment = null,
        public ?string $senderId = null,
    ) {}

    /**
     * Create from an array (for API requests).
     *
     * @param array{
     *     target_id: string,
     *     target_type: string,
     *     feedback: int,
     *     comment?: string|null,
     *     sender_id?: string|null
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $feedback = FeedbackEnum::fromInt($data['feedback']);
        
        if ($feedback === null) {
            throw new \InvalidArgumentException('Invalid feedback value. Must be -1, 0, or 1.');
        }

        return new self(
            targetId: $data['target_id'],
            targetType: $data['target_type'],
            feedback: $feedback,
            comment: $data['comment'] ?? null,
            senderId: $data['sender_id'] ?? null,
        );
    }

    /**
     * Convert to array (for API responses).
     *
     * @return array{
     *     target_id: string,
     *     target_type: string,
     *     feedback: int,
     *     feedback_label: string,
     *     feedback_icon: string,
     *     comment: string|null,
     *     sender_id: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'target_id' => $this->targetId,
            'target_type' => $this->targetType,
            'feedback' => $this->feedback->value,
            'feedback_label' => $this->feedback->label(),
            'feedback_icon' => $this->feedback->icon(),
            'comment' => $this->comment,
            'sender_id' => $this->senderId,
        ];
    }

    /**
     * Check if this is positive feedback.
     */
    public function isPositive(): bool
    {
        return $this->feedback->isPositive();
    }

    /**
     * Check if this is negative feedback.
     */
    public function isNegative(): bool
    {
        return $this->feedback->isNegative();
    }

    /**
     * Check if this is neutral feedback.
     */
    public function isNeutral(): bool
    {
        return $this->feedback->isNeutral();
    }

    /**
     * Check if the target type is a message.
     */
    public function isMessageFeedback(): bool
    {
        return $this->targetType === 'message';
    }

    /**
     * Check if the target type is a conversation.
     */
    public function isConversationFeedback(): bool
    {
        return $this->targetType === 'conversation';
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Feedback values for conversations and messages.
 *
 * Used for the 3-set feedback system: thumbs up, thumbs down, neutral.
 */
enum FeedbackEnum: int
{
    case NEGATIVE = -1;  // Thumbs down
    case NEUTRAL = 0;    // Neutral / no strong opinion
    case POSITIVE = 1;   // Thumbs up

    /**
     * Get the label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::POSITIVE => '👍 Helpful',
            self::NEUTRAL => '😐 Neutral',
            self::NEGATIVE => '👎 Not Helpful',
        };
    }

    /**
     * Get the icon for display.
     */
    public function icon(): string
    {
        return match ($this) {
            self::POSITIVE => '👍',
            self::NEUTRAL => '😐',
            self::NEGATIVE => '👎',
        };
    }

    /**
     * Check if this is positive feedback.
     */
    public function isPositive(): bool
    {
        return $this === self::POSITIVE;
    }

    /**
     * Check if this is negative feedback.
     */
    public function isNegative(): bool
    {
        return $this === self::NEGATIVE;
    }

    /**
     * Check if this is neutral feedback.
     */
    public function isNeutral(): bool
    {
        return $this === self::NEUTRAL;
    }

    /**
     * Create from integer value.
     */
    public static function fromInt(?int $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return match ($value) {
            1 => self::POSITIVE,
            0 => self::NEUTRAL,
            -1 => self::NEGATIVE,
            default => null,
        };
    }

    /**
     * Get all cases as options for a select input.
     *
     * @return array<int, array{value: int, label: string, icon: string}>
     */
    public static function asOptions(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'icon' => $case->icon(),
        ], self::cases());
    }
}

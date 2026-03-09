<?php

namespace App\Enums;

enum EpisodicEventTypeEnum: string
{
    case CORRECTION = 'correction';
    case PREFERENCE_LEARNED = 'preference_learned';
    case FACT_STORED = 'fact_stored';
    case TASK_COMPLETED = 'task_completed';
    case DELEGATION_RESULT = 'delegation_result';

    /**
     * Get default importance score for this event type.
     * Based on Ebbinghaus-inspired importance weighting.
     */
    public function defaultImportance(): float
    {
        return match ($this) {
            self::CORRECTION => 0.90,
            self::PREFERENCE_LEARNED => 0.80,
            self::FACT_STORED => 0.60,
            self::TASK_COMPLETED => 0.50,
            self::DELEGATION_RESULT => 0.50,
        };
    }

    /**
     * Get all event types as options for a select.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
            ->toArray();
    }

    /**
     * Human-readable label with emoji.
     */
    public function label(): string
    {
        return match ($this) {
            self::CORRECTION => '⚠️ Correction',
            self::PREFERENCE_LEARNED => '⭐ Preference Learned',
            self::FACT_STORED => '📌 Fact Stored',
            self::TASK_COMPLETED => '✅ Task Completed',
            self::DELEGATION_RESULT => '🔄 Delegation Result',
        };
    }

    /**
     * Short label without emoji.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::CORRECTION => 'Correction',
            self::PREFERENCE_LEARNED => 'Preference',
            self::FACT_STORED => 'Fact',
            self::TASK_COMPLETED => 'Task',
            self::DELEGATION_RESULT => 'Delegation',
        };
    }
}

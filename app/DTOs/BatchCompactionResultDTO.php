<?php

namespace App\DTOs;

/**
 * Data Transfer Object for batch compaction results.
 *
 * Used when compacting multiple conversations at once,
 * aggregating results and error information.
 */
class BatchCompactionResultDTO
{
    /**
     * @param  int  $compacted  Number of conversations successfully compacted
     * @param  int  $skipped  Number of conversations skipped (no compaction needed)
     * @param  int  $errors  Number of conversations that encountered errors
     * @param  array<int, string>  $errorDetails  Error messages keyed by conversation ID
     * @param  array<int, CompactionResultDTO>  $results  Individual results keyed by conversation ID
     */
    public function __construct(
        public readonly int $compacted = 0,
        public readonly int $skipped = 0,
        public readonly int $errors = 0,
        public readonly array $errorDetails = [],
        public readonly array $results = [],
    ) {}

    /**
     * Create from an array of individual results.
     *
     * @param  array<int, CompactionResultDTO>  $results  Results keyed by conversation ID
     * @param  array<int, string>  $errors  Error messages keyed by conversation ID
     */
    public static function fromResults(array $results, array $errors = []): self
    {
        $compacted = 0;
        $skipped = 0;

        foreach ($results as $conversationId => $result) {
            if ($result->actionTaken) {
                $compacted++;
            } else {
                $skipped++;
            }
        }

        return new self(
            compacted: $compacted,
            skipped: $skipped,
            errors: count($errors),
            errorDetails: $errors,
            results: $results,
        );
    }

    /**
     * Create an empty result.
     */
    public static function empty(): self
    {
        return new self;
    }

    /**
     * Check if there were any errors.
     */
    public function hasErrors(): bool
    {
        return $this->errors > 0;
    }

    /**
     * Get total number of conversations processed.
     */
    public function total(): int
    {
        return $this->compacted + $this->skipped + $this->errors;
    }

    /**
     * Check if any compaction was performed.
     */
    public function hasCompactions(): bool
    {
        return $this->compacted > 0;
    }

    /**
     * Get a summary string for display.
     */
    public function summary(): string
    {
        return sprintf(
            'Compacted: %d, Skipped: %d, Errors: %d',
            $this->compacted,
            $this->skipped,
            $this->errors
        );
    }

    /**
     * Merge with another batch result.
     */
    public function merge(self $other): self
    {
        return new self(
            compacted: $this->compacted + $other->compacted,
            skipped: $this->skipped + $other->skipped,
            errors: $this->errors + $other->errors,
            errorDetails: array_merge($this->errorDetails, $other->errorDetails),
            results: array_merge($this->results, $other->results),
        );
    }
}

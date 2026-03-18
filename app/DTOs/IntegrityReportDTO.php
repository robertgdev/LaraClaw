<?php

namespace App\DTOs;

use Carbon\CarbonInterface;

/**
 * Data Transfer Object for an integrity report.
 *
 * Contains the results of all integrity checks for a conversation.
 */
readonly class IntegrityReportDTO
{
    /**
     * @param  array<IntegrityCheckDTO>  $checks
     */
    public function __construct(
        public int $conversationId,
        public array $checks,
        public int $passCount,
        public int $failCount,
        public int $warnCount,
        public CarbonInterface $scannedAt,
    ) {}

    /**
     * Create from array of checks.
     *
     * @param  array<IntegrityCheckDTO>  $checks
     */
    public static function fromChecks(int $conversationId, array $checks): self
    {
        $passCount = 0;
        $failCount = 0;
        $warnCount = 0;

        foreach ($checks as $check) {
            if ($check->isPass()) {
                $passCount++;
            } elseif ($check->isFail()) {
                $failCount++;
            } elseif ($check->isWarn()) {
                $warnCount++;
            }
        }

        return new self(
            conversationId: $conversationId,
            checks: $checks,
            passCount: $passCount,
            failCount: $failCount,
            warnCount: $warnCount,
            scannedAt: now(),
        );
    }

    /**
     * Check if all checks passed.
     */
    public function isHealthy(): bool
    {
        return $this->failCount === 0 && $this->warnCount === 0;
    }

    /**
     * Check if there are any failures.
     */
    public function hasFailures(): bool
    {
        return $this->failCount > 0;
    }

    /**
     * Check if there are any warnings.
     */
    public function hasWarnings(): bool
    {
        return $this->warnCount > 0;
    }

    /**
     * Get all failing checks.
     *
     * @return array<IntegrityCheckDTO>
     */
    public function getFailures(): array
    {
        return array_filter($this->checks, fn ($check) => $check->isFail());
    }

    /**
     * Get all warnings.
     *
     * @return array<IntegrityCheckDTO>
     */
    public function getWarnings(): array
    {
        return array_filter($this->checks, fn ($check) => $check->isWarn());
    }

    /**
     * Convert to array.
     *
     * @return array<string, int|string|bool|array<int, array<string, mixed>>>
     */
    public function toArray(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'checks' => array_map(fn ($check) => $check->toArray(), $this->checks),
            'pass_count' => $this->passCount,
            'fail_count' => $this->failCount,
            'warn_count' => $this->warnCount,
            'scanned_at' => $this->scannedAt->toIso8601String(),
            'is_healthy' => $this->isHealthy(),
        ];
    }
}

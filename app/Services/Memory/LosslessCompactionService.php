<?php

namespace App\Services\Memory;

use App\DTOs\BatchCompactionResultDTO;
use App\DTOs\CompactionResultDTO;
use App\DTOs\IntegrityReportDTO;
use App\Logging\MultiLogger;
use App\Models\Conversation;
use App\Services\MemoryEngineService;

/**
 * Lossless Compaction Service.
 *
 * Orchestrates lossless memory compaction operations, providing a clean
 * interface for both CLI commands and queue jobs.
 *
 * This service handles:
 * - Single conversation compaction
 * - Batch compaction of multiple conversations
 * - Integrity checking and reporting
 * - Configurable summarization strategies
 */
class LosslessCompactionService
{
    /** @var \Closure|null */
    private $summarizer = null;

    private MemoryEngineService $memory;

    public function __construct(?MemoryEngineService $memory = null)
    {
        $this->memory = $memory ?? app(MemoryEngineService::class);
    }

    // ==========================================
    // Configuration
    // ==========================================

    /**
     * Check if lossless memory is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->memory->isLosslessEnabled();
    }

    /**
     * Set a custom summarizer callable.
     *
     * The callable receives: (string $content, bool $aggressive, array $options)
     * and should return a summary string.
     */
    public function setSummarizer(callable $summarizer): self
    {
        $this->summarizer = $summarizer;

        return $this;
    }

    /**
     * Get the configured summarizer or default.
     */
    protected function getSummarizer(): callable
    {
        return $this->summarizer ?? $this->defaultSummarizer();
    }

    /**
     * Default summarizer using simple truncation.
     * In production, this should be replaced with an LLM-based summarizer.
     */
    protected function defaultSummarizer(): callable
    {
        return function (string $content, bool $aggressive, array $options): string {
            $maxLen = $aggressive ? 500 : 1000;
            if (strlen($content) <= $maxLen) {
                return $content;
            }

            return substr($content, 0, $maxLen)."\n[Truncated - use LLM for proper summarization]";
        };
    }

    /**
     * Get the configured token budget.
     */
    protected function getTokenBudget(): int
    {
        return (int) config('laraclaw.memory.lossless_token_budget', 100000);
    }

    // ==========================================
    // Compaction Operations
    // ==========================================

    /**
     * Compact a single conversation.
     *
     * @param  int  $conversationId  The conversation ID to compact
     * @param  int|null  $tokenBudget  Override the default token budget
     * @param  bool  $dryRun  If true, only evaluate without making changes
     * @return CompactionResultDTO The compaction result
     */
    public function compactConversation(
        int $conversationId,
        ?int $tokenBudget = null,
        bool $dryRun = false
    ): CompactionResultDTO {
        $budget = $tokenBudget ?? $this->getTokenBudget();

        // Evaluate if compaction is needed
        $decision = $this->memory->evaluateCompaction($conversationId, $budget);

        if (! $decision->shouldCompact) {
            return CompactionResultDTO::noAction($decision->currentTokens);
        }

        if ($dryRun) {
            // Return a result indicating what would happen
            return new CompactionResultDTO(
                actionTaken: false,
                tokensBefore: $decision->currentTokens,
                tokensAfter: $decision->currentTokens,
                createdSummaryId: null,
                condensed: false,
                level: 'dry-run',
            );
        }

        // Run compaction with the configured summarizer
        return $this->memory->compact(
            $conversationId,
            $budget,
            $this->getSummarizer()
        );
    }

    /**
     * Compact multiple conversations.
     *
     * @param  array<int>  $conversationIds  Array of conversation IDs
     * @param  int|null  $tokenBudget  Override the default token budget
     * @param  bool  $dryRun  If true, only evaluate without making changes
     * @return BatchCompactionResultDTO Aggregated results
     */
    public function compactConversations(
        array $conversationIds,
        ?int $tokenBudget = null,
        bool $dryRun = false
    ): BatchCompactionResultDTO {
        $results = [];
        $errors = [];

        foreach ($conversationIds as $conversationId) {
            try {
                $results[$conversationId] = $this->compactConversation(
                    $conversationId,
                    $tokenBudget,
                    $dryRun
                );
            } catch (\Exception $e) {
                $errors[$conversationId] = $e->getMessage();
                MultiLogger::error('Lossless compaction failed', [
                    'conversation_id' => $conversationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return BatchCompactionResultDTO::fromResults($results, $errors);
    }

    /**
     * Compact all conversations with context items.
     *
     * @param  bool  $dryRun  If true, only evaluate without making changes
     * @return BatchCompactionResultDTO Aggregated results
     */
    public function compactAll(bool $dryRun = false): BatchCompactionResultDTO
    {
        $conversations = Conversation::has('contextItems')->get();

        if ($conversations->isEmpty()) {
            return BatchCompactionResultDTO::empty();
        }

        $conversationIds = $conversations->pluck('id')->toArray();

        return $this->compactConversations($conversationIds, null, $dryRun);
    }

    // ==========================================
    // Integrity Operations
    // ==========================================

    /**
     * Check integrity for a single conversation.
     *
     * @param  int  $conversationId  The conversation ID to check
     * @return IntegrityReportDTO The integrity report
     */
    public function checkIntegrity(int $conversationId): IntegrityReportDTO
    {
        return $this->memory->checkIntegrity($conversationId);
    }

    /**
     * Check integrity for multiple conversations.
     *
     * @param  array<int>  $conversationIds  Array of conversation IDs
     * @return array<int, IntegrityReportDTO> Reports keyed by conversation ID
     */
    public function checkIntegrityBatch(array $conversationIds): array
    {
        $reports = [];

        foreach ($conversationIds as $conversationId) {
            try {
                $reports[$conversationId] = $this->checkIntegrity($conversationId);
            } catch (\Exception $e) {
                MultiLogger::error('Integrity check failed', [
                    'conversation_id' => $conversationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $reports;
    }

    /**
     * Check integrity for all conversations with context items.
     *
     * @return array{healthy: int, unhealthy: int, reports: array<int, IntegrityReportDTO>}
     */
    public function checkIntegrityAll(): array
    {
        $conversations = Conversation::has('contextItems')->get();

        $healthy = 0;
        $unhealthy = 0;
        $reports = [];

        foreach ($conversations as $conversation) {
            $report = $this->checkIntegrity($conversation->id);
            $reports[$conversation->id] = $report;

            if ($report->isHealthy()) {
                $healthy++;
            } else {
                $unhealthy++;
            }
        }

        return [
            'healthy' => $healthy,
            'unhealthy' => $unhealthy,
            'reports' => $reports,
        ];
    }

    /**
     * Get repair suggestions for an integrity report.
     *
     * @return array<string>
     */
    public function getRepairPlan(IntegrityReportDTO $report): array
    {
        return $this->memory->getRepairPlan($report);
    }

    // ==========================================
    // Utility Methods
    // ==========================================

    /**
     * Get context token count for a conversation.
     */
    public function getContextTokenCount(int $conversationId): int
    {
        return $this->memory->getContextTokenCount($conversationId);
    }

    /**
     * Evaluate if compaction is needed for a conversation.
     *
     * @return array{should_compact: bool, current_tokens: int, threshold: int, token_budget: int}
     */
    public function evaluateCompaction(int $conversationId, ?int $tokenBudget = null): array
    {
        $budget = $tokenBudget ?? $this->getTokenBudget();
        $decision = $this->memory->evaluateCompaction($conversationId, $budget);

        return [
            'should_compact' => $decision->shouldCompact,
            'current_tokens' => $decision->currentTokens,
            'threshold' => $decision->threshold,
            'token_budget' => $budget,
        ];
    }
}

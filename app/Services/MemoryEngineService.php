<?php

namespace App\Services;

use App\DTOs\CompactionDecisionDTO;
use App\DTOs\CompactionResultDTO;
use App\DTOs\ContextItemDTO;
use App\DTOs\IntegrityReportDTO;
use App\DTOs\SummaryRecordDTO;
use App\Helpers\TokenEstimatorHelper;
use App\Models\ConversationMessage;
use App\Services\Memory\CompactionEngine;
use App\Services\Memory\IntegrityChecker;
use App\Services\Memory\SummaryStore;

/**
 * Memory Engine - Lossless Memory System.
 *
 * Provides hierarchical memory management with:
 * - Hierarchical summaries with depth levels
 * - Context items with ordered ordinals
 * - Fresh tail protection for recent messages
 * - Three-level summarization escalation
 *
 * This service acts as a façade, delegating to:
 * - {@see SummaryStore} for lossless memory persistence
 * - {@see CompactionEngine} for lossless memory compaction
 * - {@see IntegrityChecker} for lossless memory integrity validation
 */
class MemoryEngineService
{
    private SummaryStore $summaryStore;

    private CompactionEngine $compactionEngine;

    private IntegrityChecker $integrityChecker;

    public function __construct(
        ?SummaryStore $summaryStore = null,
        ?CompactionEngine $compactionEngine = null,
        ?IntegrityChecker $integrityChecker = null
    ) {
        $this->summaryStore = $summaryStore ?? new SummaryStore;
        $this->integrityChecker = $integrityChecker ?? new IntegrityChecker($this->summaryStore);

        // Create CompactionEngine with config values
        $this->compactionEngine = $compactionEngine ?? new CompactionEngine(
            $this->summaryStore,
            [
                'context_threshold' => (float) config('laraclaw.memory.lossless_context_threshold', 0.75),
                'fresh_tail_count' => (int) config('laraclaw.memory.lossless_fresh_tail', 8),
            ]
        );
    }

    /**
     * Check if lossless memory is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->isLosslessEnabled();
    }

    /**
     * Check if lossless memory is enabled.
     */
    public function isLosslessEnabled(): bool
    {
        return config('laraclaw.memory.lossless_enabled', true);
    }

    // ==========================================
    // Lossless Memory Operations
    // ==========================================

    /**
     * Get the summary store instance.
     */
    public function getSummaryStore(): SummaryStore
    {
        return $this->summaryStore;
    }

    /**
     * Get the compaction engine instance.
     */
    public function getCompactionEngine(): CompactionEngine
    {
        return $this->compactionEngine;
    }

    /**
     * Get the integrity checker instance.
     */
    public function getIntegrityChecker(): IntegrityChecker
    {
        return $this->integrityChecker;
    }

    /**
     * Append a message to the lossless context.
     */
    public function appendMessageToContext(int $conversationId, int $messageId): void
    {
        $this->summaryStore->appendContextMessage($conversationId, $messageId);
    }

    /**
     * Append multiple messages to the lossless context.
     *
     * @param  array<int>  $messageIds
     */
    public function appendMessagesToContext(int $conversationId, array $messageIds): void
    {
        $this->summaryStore->appendContextMessages($conversationId, $messageIds);
    }

    /**
     * Get context items for a conversation.
     *
     * @return array<ContextItemDTO>
     */
    public function getContextItems(int $conversationId): array
    {
        return $this->summaryStore->getContextItems($conversationId);
    }

    /**
     * Get total token count for a conversation's context.
     */
    public function getContextTokenCount(int $conversationId): int
    {
        return $this->summaryStore->getContextTokenCount($conversationId);
    }

    /**
     * Get all summaries for a conversation.
     *
     * @return array<SummaryRecordDTO>
     */
    public function getSummaries(int $conversationId): array
    {
        return $this->summaryStore->getSummariesByConversation($conversationId);
    }

    /**
     * Get a specific summary by ID.
     */
    public function getSummary(string $summaryId): ?SummaryRecordDTO
    {
        return $this->summaryStore->getSummary($summaryId);
    }

    /**
     * Evaluate whether compaction is needed.
     */
    public function evaluateCompaction(
        int $conversationId,
        int $tokenBudget,
        ?int $observedTokenCount = null
    ): CompactionDecisionDTO {
        return $this->compactionEngine->evaluate($conversationId, $tokenBudget, $observedTokenCount);
    }

    /**
     * Run compaction on a conversation.
     *
     * @param  callable|null  $summarize  Function to generate summaries (receives content, returns summary)
     */
    public function compact(
        int $conversationId,
        int $tokenBudget,
        ?callable $summarize = null,
        bool $force = false,
        bool $hardTrigger = false
    ): CompactionResultDTO {
        return $this->compactionEngine->compact(
            $conversationId,
            $tokenBudget,
            $summarize,
            $force,
            $hardTrigger
        );
    }

    /**
     * Compact until under target tokens.
     */
    public function compactUntilUnder(
        int $conversationId,
        int $tokenBudget,
        ?int $targetTokens = null,
        ?int $currentTokens = null,
        ?callable $summarize = null
    ): array {
        return $this->compactionEngine->compactUntilUnder(
            $conversationId,
            $tokenBudget,
            $targetTokens,
            $currentTokens,
            $summarize
        );
    }

    /**
     * Run integrity check on a conversation.
     */
    public function checkIntegrity(int $conversationId): IntegrityReportDTO
    {
        return $this->integrityChecker->scan($conversationId);
    }

    /**
     * Get repair suggestions for integrity issues.
     *
     * @return array<string>
     */
    public function getRepairPlan(IntegrityReportDTO $report): array
    {
        return IntegrityChecker::repairPlan($report);
    }

    /**
     * Build context string from lossless memory for agent prompts.
     */
    public function getLosslessContextForAgent(int $conversationId, int $maxTokens = 4000): string
    {
        $contextItems = $this->summaryStore->getContextItems($conversationId);
        $sections = [];
        $currentTokens = 0;

        // Process items in reverse order (newest first) to prioritize recent context
        $reversedItems = array_reverse($contextItems);

        foreach ($reversedItems as $item) {
            $content = '';
            $tokens = 0;

            if ($item->isMessage() && $item->messageId) {
                $message = ConversationMessage::find($item->messageId);
                if ($message) {
                    $content = $message->message;
                    $tokens = TokenEstimatorHelper::estimate($content ?? '');
                }
            } elseif ($item->isSummary() && $item->summaryId) {
                $summary = $this->summaryStore->getSummary($item->summaryId);
                if ($summary) {
                    $depthLabel = $summary->depth > 0 ? " (depth {$summary->depth})" : '';
                    $content = "[Summary{$depthLabel}]\n{$summary->content}";
                    $tokens = $summary->tokenCount;
                }
            }

            if ($content && $currentTokens + $tokens <= $maxTokens) {
                array_unshift($sections, $content);
                $currentTokens += $tokens;
            }

            if ($currentTokens >= $maxTokens) {
                break;
            }
        }

        return implode("\n\n---\n\n", $sections);
    }
}

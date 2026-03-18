<?php

namespace App\Services\Memory;

use App\DTOs\CompactionDecisionDTO;
use App\DTOs\CompactionResultDTO;
use App\Helpers\TokenEstimatorHelper;
use App\Models\ConversationMessage;
use App\Models\MemorySummary;
use Carbon\CarbonInterface;

/**
 * Compaction Engine service.
 *
 * Implements the lossless memory compaction algorithm with:
 * - Two-phase compaction: leaf pass (messages → summaries) and condensed pass (summaries → higher-level summaries)
 * - Fresh tail protection: preserves recent N messages
 * - Three-level escalation: normal → aggressive → fallback
 * - Depth-aware hierarchical summaries
 */
class CompactionEngine
{
    /**
     * Default configuration values.
     */
    private const float DEFAULT_CONTEXT_THRESHOLD = 0.75;

    private const int DEFAULT_FRESH_TAIL_COUNT = 8;

    private const int DEFAULT_LEAF_MIN_FANOUT = 8;

    private const int DEFAULT_CONDENSED_MIN_FANOUT = 4;

    private const int DEFAULT_CONDENSED_MIN_FANOUT_HARD = 2;

    private const int DEFAULT_LEAF_CHUNK_TOKENS = 20000;

    private const int DEFAULT_LEAF_TARGET_TOKENS = 600;

    private const int DEFAULT_CONDENSED_TARGET_TOKENS = 900;

    private const int DEFAULT_MAX_ROUNDS = 10;

    private const int FALLBACK_MAX_CHARS = 2048;

    private SummaryStore $summaryStore;

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param  array<string, mixed>|null  $config
     */
    public function __construct(
        SummaryStore $summaryStore,
        ?array $config = null
    ) {
        $this->summaryStore = $summaryStore;
        $this->config = array_merge([
            'context_threshold' => self::DEFAULT_CONTEXT_THRESHOLD,
            'fresh_tail_count' => self::DEFAULT_FRESH_TAIL_COUNT,
            'leaf_min_fanout' => self::DEFAULT_LEAF_MIN_FANOUT,
            'condensed_min_fanout' => self::DEFAULT_CONDENSED_MIN_FANOUT,
            'condensed_min_fanout_hard' => self::DEFAULT_CONDENSED_MIN_FANOUT_HARD,
            'leaf_chunk_tokens' => self::DEFAULT_LEAF_CHUNK_TOKENS,
            'leaf_target_tokens' => self::DEFAULT_LEAF_TARGET_TOKENS,
            'condensed_target_tokens' => self::DEFAULT_CONDENSED_TARGET_TOKENS,
            'max_rounds' => self::DEFAULT_MAX_ROUNDS,
            'timezone' => 'UTC',
        ], $config ?? []);
    }

    /**
     * Evaluate whether compaction is needed.
     */
    public function evaluate(
        int $conversationId,
        int $tokenBudget,
        ?int $observedTokenCount = null
    ): CompactionDecisionDTO {
        $storedTokens = $this->summaryStore->getContextTokenCount($conversationId);
        $liveTokens = $observedTokenCount && $observedTokenCount > 0 ? $observedTokenCount : 0;
        $currentTokens = max($storedTokens, $liveTokens);
        $threshold = (int) floor($this->config['context_threshold'] * $tokenBudget);

        if ($currentTokens > $threshold) {
            return CompactionDecisionDTO::threshold($currentTokens, $threshold);
        }

        return CompactionDecisionDTO::none($currentTokens, $threshold);
    }

    /**
     * Evaluate whether the leaf trigger is active.
     *
     * @return array{should_compact: bool, raw_tokens_outside_tail: int, threshold: int}
     */
    public function evaluateLeafTrigger(int $conversationId): array
    {
        $rawTokensOutsideTail = $this->countRawTokensOutsideFreshTail($conversationId);
        $threshold = $this->config['leaf_chunk_tokens'];

        return [
            'should_compact' => $rawTokensOutsideTail >= $threshold,
            'raw_tokens_outside_tail' => $rawTokensOutsideTail,
            'threshold' => $threshold,
        ];
    }

    /**
     * Run a full compaction sweep.
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
        return $this->compactFullSweep(
            $conversationId,
            $tokenBudget,
            $summarize,
            $force,
            $hardTrigger
        );
    }

    /**
     * Run a single leaf pass.
     */
    public function compactLeaf(
        int $conversationId,
        int $tokenBudget,
        ?callable $summarize = null,
        bool $force = false,
        ?string $previousSummaryContent = null
    ): CompactionResultDTO {
        $tokensBefore = $this->summaryStore->getContextTokenCount($conversationId);
        $threshold = (int) floor($this->config['context_threshold'] * $tokenBudget);
        $leafTrigger = $this->evaluateLeafTrigger($conversationId);

        if (! $force && $tokensBefore <= $threshold && ! $leafTrigger['should_compact']) {
            return CompactionResultDTO::noAction($tokensBefore);
        }

        $leafChunk = $this->selectOldestLeafChunk($conversationId);
        if (empty($leafChunk['items'])) {
            return CompactionResultDTO::noAction($tokensBefore);
        }

        $previousContent = $previousSummaryContent
            ?? $this->resolvePriorLeafSummaryContext($conversationId, $leafChunk['items']);

        $leafResult = $this->leafPass(
            $conversationId,
            $leafChunk['items'],
            $summarize,
            $previousContent
        );

        $tokensAfter = $this->summaryStore->getContextTokenCount($conversationId);

        return CompactionResultDTO::leaf(
            $tokensBefore,
            $tokensAfter,
            $leafResult['summary_id'],
            $leafResult['level']
        );
    }

    /**
     * Run a full compaction sweep with both phases.
     */
    public function compactFullSweep(
        int $conversationId,
        int $tokenBudget,
        ?callable $summarize = null,
        bool $force = false,
        bool $hardTrigger = false
    ): CompactionResultDTO {
        $tokensBefore = $this->summaryStore->getContextTokenCount($conversationId);
        $threshold = (int) floor($this->config['context_threshold'] * $tokenBudget);
        $leafTrigger = $this->evaluateLeafTrigger($conversationId);

        if (! $force && $tokensBefore <= $threshold && ! $leafTrigger['should_compact']) {
            return CompactionResultDTO::noAction($tokensBefore);
        }

        $contextItems = $this->summaryStore->getContextItems($conversationId);
        if (empty($contextItems)) {
            return CompactionResultDTO::noAction($tokensBefore);
        }

        $actionTaken = false;
        $condensed = false;
        $createdSummaryId = null;
        $level = null;
        $previousSummaryContent = null;
        $previousTokens = $tokensBefore;

        // Phase 1: Leaf passes
        while (true) {
            $leafChunk = $this->selectOldestLeafChunk($conversationId);
            if (empty($leafChunk['items'])) {
                break;
            }

            $passTokensBefore = $this->summaryStore->getContextTokenCount($conversationId);
            $leafResult = $this->leafPass(
                $conversationId,
                $leafChunk['items'],
                $summarize,
                $previousSummaryContent
            );
            $passTokensAfter = $this->summaryStore->getContextTokenCount($conversationId);

            $actionTaken = true;
            $createdSummaryId = $leafResult['summary_id'];
            $level = $leafResult['level'];
            $previousSummaryContent = $leafResult['content'] ?? null;

            if ($passTokensAfter >= $passTokensBefore || $passTokensAfter >= $previousTokens) {
                break;
            }
            $previousTokens = $passTokensAfter;
        }

        // Phase 2: Condensed passes
        while (true) {
            $candidate = $this->selectShallowestCondensationCandidate($conversationId, $hardTrigger);
            if ($candidate === null) {
                break;
            }

            $passTokensBefore = $this->summaryStore->getContextTokenCount($conversationId);
            $condenseResult = $this->condensedPass(
                $conversationId,
                $candidate['items'],
                $candidate['target_depth'],
                $summarize
            );
            $passTokensAfter = $this->summaryStore->getContextTokenCount($conversationId);

            $actionTaken = true;
            $condensed = true;
            $createdSummaryId = $condenseResult['summary_id'];
            $level = $condenseResult['level'];

            if ($passTokensAfter >= $passTokensBefore || $passTokensAfter >= $previousTokens) {
                break;
            }
            $previousTokens = $passTokensAfter;
        }

        $tokensAfter = $this->summaryStore->getContextTokenCount($conversationId);

        if (! $actionTaken) {
            return CompactionResultDTO::noAction($tokensBefore);
        }

        return new CompactionResultDTO(
            actionTaken: true,
            tokensBefore: $tokensBefore,
            tokensAfter: $tokensAfter,
            createdSummaryId: $createdSummaryId,
            condensed: $condensed,
            level: $level,
        );
    }

    /**
     * Compact until under target.
     *
     * @return array{success: bool, rounds: int, final_tokens: int}
     */
    public function compactUntilUnder(
        int $conversationId,
        int $tokenBudget,
        ?int $targetTokens = null,
        ?int $currentTokens = null,
        ?callable $summarize = null
    ): array {
        $target = $targetTokens && $targetTokens > 0 ? $targetTokens : $tokenBudget;

        $storedTokens = $this->summaryStore->getContextTokenCount($conversationId);
        $liveTokens = $currentTokens && $currentTokens > 0 ? $currentTokens : 0;
        $lastTokens = max($storedTokens, $liveTokens);

        if ($lastTokens < $target) {
            return ['success' => true, 'rounds' => 0, 'final_tokens' => $lastTokens];
        }

        for ($round = 1; $round <= $this->config['max_rounds']; $round++) {
            $result = $this->compact(
                $conversationId,
                $tokenBudget,
                $summarize,
                force: true
            );

            if ($result->tokensAfter <= $target) {
                return ['success' => true, 'rounds' => $round, 'final_tokens' => $result->tokensAfter];
            }

            if (! $result->actionTaken || $result->tokensAfter >= $lastTokens) {
                return ['success' => false, 'rounds' => $round, 'final_tokens' => $result->tokensAfter];
            }

            $lastTokens = $result->tokensAfter;
        }

        $finalTokens = $this->summaryStore->getContextTokenCount($conversationId);

        return [
            'success' => $finalTokens <= $target,
            'rounds' => $this->config['max_rounds'],
            'final_tokens' => $finalTokens,
        ];
    }

    /**
     * Run a leaf pass - summarize messages into a leaf summary.
     *
     * @param  array<int, object{messageId: int|null, ordinal: int}>  $messageItems
     * @return array{summary_id: string, level: string, content: string|null}
     */
    private function leafPass(
        int $conversationId,
        array $messageItems,
        ?callable $summarize,
        ?string $previousSummaryContent = null
    ): array {
        // Fetch message contents
        $messageContents = [];
        foreach ($messageItems as $item) {
            if ($item->messageId === null) {
                continue;
            }
            $message = ConversationMessage::find($item->messageId);
            if ($message) {
                $messageContents[] = [
                    'message_id' => $message->id,
                    'content' => $message->message,
                    'created_at' => $message->created_at,
                    'token_count' => $this->estimateTokens($message->message ?? ''),
                ];
            }
        }

        if (empty($messageContents)) {
            return ['summary_id' => '', 'level' => 'fallback', 'content' => ''];
        }

        // Concatenate messages with timestamps
        $concatenated = collect($messageContents)
            ->map(fn ($m) => "[{$this->formatTimestamp($m['created_at'])}]\n{$m['content']}")
            ->join("\n\n");

        // Generate summary
        $summaryResult = $this->summarizeWithEscalation(
            $concatenated,
            $summarize,
            ['previous_summary' => $previousSummaryContent, 'is_condensed' => false]
        );

        // Create summary record
        $summaryId = MemorySummary::generateId($summaryResult['content']);
        $tokenCount = $this->estimateTokens($summaryResult['content']);

        $this->summaryStore->insertSummary([
            'summary_id' => $summaryId,
            'conversation_id' => $conversationId,
            'kind' => 'leaf',
            'depth' => 0,
            'content' => $summaryResult['content'],
            'token_count' => $tokenCount,
            'earliest_at' => min(array_column($messageContents, 'created_at')),
            'latest_at' => max(array_column($messageContents, 'created_at')),
            'source_message_token_count' => array_sum(array_column($messageContents, 'token_count')),
        ]);

        // Link to source messages
        $messageIds = array_column($messageContents, 'message_id');
        $this->summaryStore->linkSummaryToMessages($summaryId, $messageIds);

        // Replace context range
        $ordinals = array_map(fn ($item) => $item->ordinal, $messageItems);
        $startOrdinal = min($ordinals);
        $endOrdinal = max($ordinals);

        $this->summaryStore->replaceContextRangeWithSummary(
            $conversationId,
            $startOrdinal,
            $endOrdinal,
            $summaryId
        );

        return [
            'summary_id' => $summaryId,
            'level' => $summaryResult['level'],
            'content' => $summaryResult['content'],
        ];
    }

    /**
     * Run a condensed pass - summarize summaries into higher-level summary.
     *
     * @param  array<int, object{summaryId: string|null, ordinal: int}>  $summaryItems
     * @return array{summary_id: string, level: string}
     */
    private function condensedPass(
        int $conversationId,
        array $summaryItems,
        int $targetDepth,
        ?callable $summarize
    ): array {
        // Fetch summary records
        $summaryRecords = [];
        foreach ($summaryItems as $item) {
            if ($item->summaryId === null) {
                continue;
            }
            $summary = $this->summaryStore->getSummary($item->summaryId);
            if ($summary) {
                $summaryRecords[] = $summary;
            }
        }

        if (empty($summaryRecords)) {
            return ['summary_id' => '', 'level' => 'fallback'];
        }

        // Concatenate summaries with time ranges
        $concatenated = collect($summaryRecords)
            ->map(function ($s) {
                $earliest = $s->earliestAt ?? $s->createdAt;
                $latest = $s->latestAt ?? $s->createdAt;
                $header = "[{$this->formatTimestamp($earliest)} - {$this->formatTimestamp($latest)}]";

                return "{$header}\n{$s->content}";
            })
            ->join("\n\n");

        // Generate summary
        $summaryResult = $this->summarizeWithEscalation(
            $concatenated,
            $summarize,
            ['is_condensed' => true, 'depth' => $targetDepth + 1]
        );

        // Create summary record
        $summaryId = MemorySummary::generateId($summaryResult['content']);
        $tokenCount = $this->estimateTokens($summaryResult['content']);

        $this->summaryStore->insertSummary([
            'summary_id' => $summaryId,
            'conversation_id' => $conversationId,
            'kind' => 'condensed',
            'depth' => $targetDepth + 1,
            'content' => $summaryResult['content'],
            'token_count' => $tokenCount,
            'earliest_at' => min(array_map(fn ($s) => $s->earliestAt ?? $s->createdAt, $summaryRecords)),
            'latest_at' => max(array_map(fn ($s) => $s->latestAt ?? $s->createdAt, $summaryRecords)),
            'descendant_count' => array_sum(array_map(fn ($s) => $s->descendantCount + 1, $summaryRecords)),
            'descendant_token_count' => array_sum(array_map(fn ($s) => $s->descendantTokenCount + $s->tokenCount, $summaryRecords)),
            'source_message_token_count' => array_sum(array_map(fn ($s) => $s->sourceMessageTokenCount, $summaryRecords)),
        ]);

        // Link to parent summaries
        $parentIds = array_map(fn ($s) => $s->summaryId, $summaryRecords);
        $this->summaryStore->linkSummaryToParents($summaryId, $parentIds);

        // Replace context range
        $ordinals = array_map(fn ($item) => $item->ordinal, $summaryItems);
        $startOrdinal = min($ordinals);
        $endOrdinal = max($ordinals);

        $this->summaryStore->replaceContextRangeWithSummary(
            $conversationId,
            $startOrdinal,
            $endOrdinal,
            $summaryId
        );

        return [
            'summary_id' => $summaryId,
            'level' => $summaryResult['level'],
        ];
    }

    /**
     * Summarize with three-level escalation.
     *
     * @param  array<string, mixed>  $options
     * @return array{content: string, level: string}
     */
    private function summarizeWithEscalation(
        string $sourceText,
        ?callable $summarize,
        array $options = []
    ): array {
        $sourceText = trim($sourceText);
        if (empty($sourceText)) {
            return ['content' => '[Truncated from 0 tokens]', 'level' => 'fallback'];
        }

        $inputTokens = max(1, $this->estimateTokens($sourceText));

        // If no summarizer provided, use fallback
        if ($summarize === null) {
            return $this->fallbackSummary($sourceText, $inputTokens);
        }

        // Try normal summarization
        $summaryText = $summarize($sourceText, false, $options);
        $level = 'normal';

        if ($this->estimateTokens($summaryText) >= $inputTokens) {
            // Try aggressive
            $summaryText = $summarize($sourceText, true, $options);
            $level = 'aggressive';

            if ($this->estimateTokens($summaryText) >= $inputTokens) {
                // Fallback to truncation
                return $this->fallbackSummary($sourceText, $inputTokens);
            }
        }

        return ['content' => $summaryText, 'level' => $level];
    }

    /**
     * Generate a fallback summary via truncation.
     *
     * @return array{content: string, level: string}
     */
    private function fallbackSummary(string $text, int $inputTokens): array
    {
        $truncated = strlen($text) > self::FALLBACK_MAX_CHARS
            ? substr($text, 0, self::FALLBACK_MAX_CHARS)
            : $text;

        return [
            'content' => "{$truncated}\n[Truncated from {$inputTokens} tokens]",
            'level' => 'fallback',
        ];
    }

    /**
     * Select the oldest leaf chunk (raw messages).
     *
     * Messages with positive feedback are preserved longer by applying
     * a threshold reduction, making them less likely to be included
     * in compaction chunks.
     *
     * @return array{items: array<int, mixed>, tokens: int}
     */
    private function selectOldestLeafChunk(int $conversationId): array
    {
        $contextItems = $this->summaryStore->getContextItems($conversationId);
        $freshTailOrdinal = $this->resolveFreshTailOrdinal($contextItems);
        $threshold = $this->config['leaf_chunk_tokens'];
        $feedbackBonus = (float) ($this->config['feedback_weight_bonus'] ?? 0.2);

        $chunk = [];
        $chunkTokens = 0;
        $started = false;

        foreach ($contextItems as $item) {
            if ($item->ordinal >= $freshTailOrdinal) {
                break;
            }

            if (! $started) {
                if (! $item->isMessage() || $item->messageId === null) {
                    continue;
                }
                $started = true;
            } elseif (! $item->isMessage() || $item->messageId === null) {
                break;
            }

            $message = ConversationMessage::find($item->messageId);
            if (! $message) {
                continue;
            }

            $messageTokens = $this->estimateTokens($message->message ?? '');

            // Apply feedback bonus: messages with positive feedback use a lower threshold
            // This makes them less likely to be included in the chunk, preserving them longer
            $effectiveThreshold = $threshold;
            if ($message->hasPositiveFeedback()) {
                $effectiveThreshold = (int) floor($threshold * (1 - $feedbackBonus));
            }

            if (count($chunk) > 0 && $chunkTokens + $messageTokens > $effectiveThreshold) {
                break;
            }

            $chunk[] = $item;
            $chunkTokens += $messageTokens;

            if ($chunkTokens >= $threshold) {
                break;
            }
        }

        return ['items' => $chunk, 'tokens' => $chunkTokens];
    }

    /**
     * Select the shallowest condensation candidate.
     *
     * @return array{target_depth: int, items: array<int, mixed>}|null
     */
    private function selectShallowestCondensationCandidate(int $conversationId, bool $hardTrigger): ?array
    {
        $contextItems = $this->summaryStore->getContextItems($conversationId);
        $freshTailOrdinal = $this->resolveFreshTailOrdinal($contextItems);
        $depthLevels = $this->summaryStore->getDistinctDepthsInContext(
            $conversationId,
            $freshTailOrdinal
        );

        foreach ($depthLevels as $targetDepth) {
            $fanout = $this->resolveFanoutForDepth($targetDepth, $hardTrigger);
            $chunk = $this->selectOldestChunkAtDepth(
                $conversationId,
                $targetDepth,
                $freshTailOrdinal
            );

            if (count($chunk['items']) >= $fanout && $chunk['tokens'] >= $this->config['condensed_target_tokens']) {
                return [
                    'target_depth' => $targetDepth,
                    'items' => $chunk['items'],
                ];
            }
        }

        return null;
    }

    /**
     * Select the oldest chunk at a specific depth.
     *
     * @return array{items: array<int, mixed>, tokens: int}
     */
    private function selectOldestChunkAtDepth(
        int $conversationId,
        int $targetDepth,
        ?int $freshTailOrdinal = null
    ): array {
        $contextItems = $this->summaryStore->getContextItems($conversationId);
        $freshTailOrdinal = $freshTailOrdinal ?? $this->resolveFreshTailOrdinal($contextItems);
        $chunkTokenBudget = $this->config['leaf_chunk_tokens'];

        $chunk = [];
        $summaryTokens = 0;
        $started = false;

        foreach ($contextItems as $item) {
            if ($item->ordinal >= $freshTailOrdinal) {
                break;
            }

            if (! $item->isSummary() || $item->summaryId === null) {
                if ($started) {
                    break;
                }

                continue;
            }

            $summary = $this->summaryStore->getSummary($item->summaryId);
            if (! $summary) {
                if ($started) {
                    break;
                }

                continue;
            }

            if ($summary->depth !== $targetDepth) {
                if ($started) {
                    break;
                }

                continue;
            }

            $started = true;

            if (count($chunk) > 0 && $summaryTokens + $summary->tokenCount > $chunkTokenBudget) {
                break;
            }

            $chunk[] = $item;
            $summaryTokens += $summary->tokenCount;

            if ($summaryTokens >= $chunkTokenBudget) {
                break;
            }
        }

        return ['items' => $chunk, 'tokens' => $summaryTokens];
    }

    /**
     * Resolve the fresh tail ordinal boundary.
     *
     * @param  array<int, object{isMessage: callable, messageId: int|null, ordinal: int}>  $contextItems
     */
    private function resolveFreshTailOrdinal(array $contextItems): int
    {
        $freshTailCount = $this->config['fresh_tail_count'];
        if ($freshTailCount <= 0) {
            return PHP_INT_MAX;
        }

        $messageItems = array_filter(
            $contextItems,
            fn ($item) => $item->isMessage() && $item->messageId !== null
        );

        if (empty($messageItems)) {
            return PHP_INT_MAX;
        }

        $messageItems = array_values($messageItems);
        $tailStartIdx = max(0, count($messageItems) - $freshTailCount);

        return $messageItems[$tailStartIdx]->ordinal ?? PHP_INT_MAX;
    }

    /**
     * Resolve prior leaf summary context.
     *
     * @param  array<int, object{messageId: int|null, ordinal: int}>  $messageItems
     */
    private function resolvePriorLeafSummaryContext(int $conversationId, array $messageItems): ?string
    {
        if (empty($messageItems)) {
            return null;
        }

        $startOrdinal = min(array_map(fn ($item) => $item->ordinal, $messageItems));
        $contextItems = $this->summaryStore->getContextItems($conversationId);

        $priorSummaries = array_filter(
            $contextItems,
            fn ($item) => $item->ordinal < $startOrdinal
                && $item->isSummary()
                && $item->summaryId !== null
        );

        $priorSummaries = array_slice(array_values($priorSummaries), -2);

        if (empty($priorSummaries)) {
            return null;
        }

        $contents = [];
        foreach ($priorSummaries as $item) {
            $summary = $this->summaryStore->getSummary($item->summaryId);
            if ($summary && trim($summary->content)) {
                $contents[] = trim($summary->content);
            }
        }

        return empty($contents) ? null : implode("\n\n", $contents);
    }

    /**
     * Resolve fanout for a given depth.
     */
    private function resolveFanoutForDepth(int $depth, bool $hardTrigger): int
    {
        if ($hardTrigger) {
            return $this->config['condensed_min_fanout_hard'];
        }
        if ($depth === 0) {
            return $this->config['leaf_min_fanout'];
        }

        return $this->config['condensed_min_fanout'];
    }

    /**
     * Count raw tokens outside fresh tail.
     */
    private function countRawTokensOutsideFreshTail(int $conversationId): int
    {
        $contextItems = $this->summaryStore->getContextItems($conversationId);
        $freshTailOrdinal = $this->resolveFreshTailOrdinal($contextItems);

        $rawTokens = 0;
        foreach ($contextItems as $item) {
            if ($item->ordinal >= $freshTailOrdinal) {
                break;
            }
            if (! $item->isMessage() || $item->messageId === null) {
                continue;
            }

            $message = ConversationMessage::find($item->messageId);
            if ($message) {
                $rawTokens += $this->estimateTokens($message->message ?? '');
            }
        }

        return $rawTokens;
    }

    /**
     * Estimate token count from content.
     */
    private function estimateTokens(string $content): int
    {
        return TokenEstimatorHelper::estimate($content);
    }

    /**
     * Format a timestamp for display.
     */
    private function formatTimestamp(CarbonInterface $timestamp): string
    {
        return $timestamp->setTimezone($this->config['timezone'])->format('Y-m-d H:i');
    }
}

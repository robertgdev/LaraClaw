<?php

namespace App\Services;

use App\DTOs\CompactionDecisionDTO;
use App\DTOs\CompactionResultDTO;
use App\DTOs\ContextItemDTO;
use App\DTOs\EpisodicEventDTO;
use App\DTOs\IntegrityReportDTO;
use App\DTOs\MemoryConsolidationDTO;
use App\DTOs\MemorySearchResultDTO;
use App\DTOs\SummaryRecordDTO;
use App\Enums\ChannelEnum;
use App\Helpers\TokenEstimatorHelper;
use App\Models\Memory;
use App\Services\Memory\CompactionEngine;
use App\Services\Memory\IntegrityChecker;
use App\Services\Memory\MemoryConsolidator;
use App\Services\Memory\MemoryRelevanceScorer;
use App\Services\Memory\SearchStrategyFactory;
use App\Services\Memory\SearchStrategyInterface;
use App\Services\Memory\SummaryStore;
use App\TypedCollections\MemorySearchResultDTOCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Adaptive Memory Engine - hybrid memory system.
 *
 * Combines two memory approaches:
 *
 * **Episodic Memory (Legacy)**:
 * - Layer 1: Episodic Memory — timestamped events with outcomes & importance scoring
 * - Layer 2: Semantic Index — Full-text search with BM25 ranking
 * - Layer 3: Temporal Decay — Ebbinghaus forgetting curve + access frequency strengthening
 *
 * **Lossless Memory (New)**:
 * - Hierarchical summaries with depth levels
 * - Context items with ordered ordinals
 * - Fresh tail protection for recent messages
 * - Three-level summarization escalation
 *
 * This service acts as a façade, delegating to:
 * - {@see MemoryRelevanceScorer} for scoring and text processing
 * - {@see MemoryConsolidator} for decay, pruning, and merge operations
 * - {@see SearchStrategyInterface} for search backend abstraction
 * - {@see SummaryStore} for lossless memory persistence
 * - {@see CompactionEngine} for lossless memory compaction
 * - {@see IntegrityChecker} for lossless memory integrity validation
 */
class MemoryEngineService
{
    private const FTS_MAX_RESULTS = 50;

    private const CONTEXT_MAX_RESULTS = 10;

    private SearchStrategyInterface $searchStrategy;

    private MemoryRelevanceScorer $scorer;

    private MemoryConsolidator $consolidator;

    private SummaryStore $summaryStore;

    private CompactionEngine $compactionEngine;

    private IntegrityChecker $integrityChecker;

    public function __construct(
        ?MemoryRelevanceScorer $scorer = null,
        ?MemoryConsolidator $consolidator = null,
        ?SummaryStore $summaryStore = null,
        ?CompactionEngine $compactionEngine = null,
        ?IntegrityChecker $integrityChecker = null
    ) {
        $this->scorer = $scorer ?? new MemoryRelevanceScorer;
        $this->consolidator = $consolidator ?? new MemoryConsolidator($this->scorer);
        $this->searchStrategy = SearchStrategyFactory::create();
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
     * Check if memory storage is enabled.
     */
    public function isEnabled(): bool
    {
        return config('laraclaw.memory.length', 200) >= 0;
    }

    /**
     * Check if lossless memory is enabled.
     */
    public function isLosslessEnabled(): bool
    {
        return config('laraclaw.memory.lossless_enabled', true);
    }

    /**
     * Truncate text based on memory length configuration.
     *
     * @return string|null Truncated text, or null if memory is disabled
     */
    public function truncateText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $length = (int) config('laraclaw.memory.length', 200);

        // -1 means memory is disabled
        if ($length < 0) {
            return null;
        }

        // 0 means no truncation
        if ($length === 0) {
            return $text;
        }

        // Truncate to configured length
        return Str::limit($text, $length);
    }

    // ==========================================
    // Episodic Memory Operations (Legacy)
    // ==========================================

    /**
     * Record an episodic event.
     *
     * @return int The auto-incremented integer ID of the created memory record.
     */
    public function recordEvent(
        string $senderId,
        ChannelEnum $channel,
        EpisodicEventDTO $event,
        ?int $conversationId = null
    ): int {
        $eventType = $event->getEventType();
        $importance = $event->importance ?? $eventType->defaultImportance();

        $memory = Memory::create([
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'channel' => $channel,
            'agent_id' => $event->agentId,
            'event_type' => $eventType,
            'content' => $event->content,
            'outcome' => $event->outcome,
            'importance' => $importance,
            'access_count' => 0,
            'last_accessed_at' => now(),
        ]);

        return (int) $memory->id;
    }

    /**
     * Search memories with hybrid scoring.
     */
    public function search(
        string $senderId,
        ChannelEnum $channel,
        string $query,
        int $limit = 20
    ): MemorySearchResultDTOCollection {
        $results = [];
        $now = now()->timestamp * 1000;

        // Layer 1: Full-text search via strategy
        $ftsResults = $this->searchStrategy->search($senderId, $channel, $query, self::FTS_MAX_RESULTS);
        $maxScore = $ftsResults->max('search_score') ?? 1.0;

        foreach ($ftsResults as $result) {
            $relevanceScore = $this->scorer->score(
                rawFtsScore: $result->search_score ?? 0,
                maxFtsScore: $maxScore,
                lastAccessedAtMs: $result->last_accessed_at->timestamp * 1000,
                accessCount: $result->access_count,
                importance: (float) $result->importance,
                nowMs: $now,
                feedbackScore: $result->feedback_score
            );

            $results[] = new MemorySearchResultDTO(
                id: $result->id,
                content: $result->content.($result->outcome ? " → {$result->outcome}" : ''),
                relevanceScore: round($relevanceScore, 4),
                source: 'episodic',
            );
        }

        // Sort by relevance and limit
        usort($results, fn ($a, $b) => $b->relevanceScore <=> $a->relevanceScore);

        return new MemorySearchResultDTOCollection(array_slice($results, 0, $limit));
    }

    /**
     * Get formatted context for agent prompt injection.
     */
    public function getContextForAgent(
        string $senderId,
        ChannelEnum $channel,
        ?string $query = null
    ): string {
        $sections = [];

        // Query-relevant memories
        if ($query) {
            $results = $this->search($senderId, $channel, $query, self::CONTEXT_MAX_RESULTS);
            if ($results->isNotEmpty()) {
                $sections[] = "\n## Relevant Memories";
                foreach ($results as $result) {
                    $icon = $result->isEpisodic() ? '📝' : '🔑';
                    $sections[] = "{$icon} {$result->content}";
                }
            }
        }

        // High-importance recent memories
        $highImportance = Memory::forSender($senderId, $channel)
            ->highImportance()
            ->latest()
            ->limit(5)
            ->get();

        if ($highImportance->isNotEmpty()) {
            $sections[] = "\n## Important Context";
            foreach ($highImportance as $event) {
                $sections[] = "{$event->event_type->label()}: {$event->content}";
            }
        }

        return implode("\n", $sections);
    }

    /**
     * Consolidate memories: decay, prune, merge.
     */
    public function consolidate(string $senderId, ChannelEnum $channel): MemoryConsolidationDTO
    {
        return $this->consolidator->consolidate($senderId, $channel);
    }

    /**
     * Strengthen a memory (bump access count).
     *
     * @param  int  $memoryId  Integer primary key of the memory record.
     */
    public function reinforce(int $memoryId): void
    {
        Memory::where('id', $memoryId)->increment('access_count', 1, [
            'last_accessed_at' => now(),
        ]);
    }

    /**
     * Get a single episodic memory by ID.
     *
     * @param  int  $id  Integer primary key of the memory record.
     */
    public function getEvent(int $id): ?Memory
    {
        return Memory::find($id);
    }

    /**
     * Get all episodic memories for a user.
     *
     * @return Collection<int, \App\Models\Memory>
     */
    public function getEvents(string $senderId, ChannelEnum $channel, ?int $limit = null): Collection
    {
        $query = Memory::forSender($senderId, $channel)
            ->latest();

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
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
                $message = \App\Models\ConversationMessage::find($item->messageId);
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

    // ==========================================
    // Legacy Accessors
    // ==========================================

    /**
     * Get the relevance scorer instance.
     */
    public function getScorer(): MemoryRelevanceScorer
    {
        return $this->scorer;
    }

    /**
     * Get the consolidator instance.
     */
    public function getConsolidator(): MemoryConsolidator
    {
        return $this->consolidator;
    }
}

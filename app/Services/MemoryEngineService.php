<?php

namespace App\Services;

use App\DTOs\EpisodicEventDTO;
use App\DTOs\MemoryConsolidationDTO;
use App\DTOs\MemorySearchResultDTO;
use App\Enums\ChannelEnum;
use App\Enums\EpisodicEventTypeEnum;
use App\Models\Memory;
use App\Services\Memory\MemoryConsolidator;
use App\Services\Memory\MemoryRelevanceScorer;
use App\Services\Memory\SearchStrategyFactory;
use App\Services\Memory\SearchStrategyInterface;
use App\TypedCollections\MemorySearchResultDTOCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Adaptive Memory Engine - 3-layer memory system.
 *
 * Layer 1: Episodic Memory — timestamped events with outcomes & importance scoring
 * Layer 2: Semantic Index — Full-text search with BM25 ranking
 * Layer 3: Temporal Decay — Ebbinghaus forgetting curve + access frequency strengthening
 *
 * This service acts as a façade, delegating to:
 * - {@see MemoryRelevanceScorer} for scoring and text processing
 * - {@see MemoryConsolidator} for decay, pruning, and merge operations
 * - {@see SearchStrategyInterface} for search backend abstraction
 */
class MemoryEngineService
{
    private const FTS_MAX_RESULTS = 50;

    private const CONTEXT_MAX_RESULTS = 10;

    private SearchStrategyInterface $searchStrategy;

    private MemoryRelevanceScorer $scorer;

    private MemoryConsolidator $consolidator;

    public function __construct(
        ?MemoryRelevanceScorer $scorer = null,
        ?MemoryConsolidator $consolidator = null
    ) {
        $this->scorer = $scorer ?? new MemoryRelevanceScorer;
        $this->consolidator = $consolidator ?? new MemoryConsolidator($this->scorer);
        $this->searchStrategy = SearchStrategyFactory::create();
    }

    /**
     * Check if memory storage is enabled.
     */
    public function isEnabled(): bool
    {
        return config('laraclaw.memory.length', 200) >= 0;
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

        $length = (int)config('laraclaw.memory.length', 200);

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
    // Core Operations
    // ==========================================

    /**
     * Record an episodic event.
     */
    public function recordEvent(
        string $senderId,
        ChannelEnum $channel,
        EpisodicEventDTO $event
    ): string {
        $id = (string) Str::uuid();

        $eventType = $event->getEventType();

        $importance = $event->importance ?? $eventType->defaultImportance();

        Memory::create([
            'id' => $id,
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

        return $id;
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
                nowMs: $now
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
     */
    public function reinforce(string $memoryId): void
    {
        Memory::where('id', $memoryId)->increment('access_count', 1, [
            'last_accessed_at' => now(),
        ]);
    }

    /**
     * Get a single episodic memory by ID.
     */
    public function getEvent(string $id): ?Memory
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

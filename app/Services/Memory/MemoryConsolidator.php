<?php

declare(strict_types=1);

namespace App\Services\Memory;

use App\DTOs\MemoryConsolidationDTO;
use App\Enums\ChannelEnum;
use App\Models\Memory;

/**
 * Handles memory consolidation operations: decay, pruning, and duplicate merging.
 *
 * This service is designed to run on a schedule (via LaraClawMemoryConsolidateCommand)
 * and keeps the memory store healthy by:
 * - Decaying importance of old, unaccessed memories
 * - Pruning low-value memories that are no longer useful
 * - Merging highly similar duplicate entries
 */
class MemoryConsolidator
{
    public function __construct(
        private readonly MemoryRelevanceScorer $scorer
    ) {}

    /**
     * Run all consolidation operations.
     */
    public function consolidate(string $senderId, ChannelEnum $channel): MemoryConsolidationDTO
    {
        return new MemoryConsolidationDTO(
            decayed: $this->decayImportance($senderId, $channel),
            pruned: $this->pruneLowValue($senderId, $channel),
            merged: $this->mergeDuplicates($senderId, $channel),
        );
    }

    /**
     * Decay importance of old unaccessed memories.
     */
    public function decayImportance(string $senderId, ChannelEnum $channel): int
    {
        $days = config('memory.consolidation.decay_after_days', 7);
        $factor = config('memory.consolidation.decay_factor', 0.95);
        $minImportance = config('memory.decay.min_importance', 0.05);

        return Memory::forSender($senderId, $channel)
            ->notAccessedFor($days)
            ->where('importance', '>', $minImportance)
            ->update(['importance' => \DB::raw("importance * {$factor}")]);
    }

    /**
     * Prune low-value memories.
     */
    public function pruneLowValue(string $senderId, ChannelEnum $channel): int
    {
        $days = config('memory.consolidation.prune_after_days', 30);
        $maxImportance = config('memory.consolidation.prune_max_importance', 0.1);

        return Memory::forSender($senderId, $channel)
            ->lowImportance($maxImportance)
            ->unaccessed()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }

    /**
     * Merge highly similar duplicate memories.
     */
    public function mergeDuplicates(string $senderId, ChannelEnum $channel): int
    {
        $threshold = config('memory.consolidation.merge_similarity_threshold', 0.8);
        $limit = config('memory.consolidation.merge_check_limit', 200);

        $merged = 0;
        $events = Memory::forSender($senderId, $channel)
            ->latest()
            ->limit($limit)
            ->get();

        $toDelete = [];

        foreach ($events as $i => $newer) {
            $newerId = (string) $newer->id;
            if (in_array($newerId, $toDelete, true)) {
                continue;
            }

            foreach ($events->skip($i + 1) as $older) {
                $olderId = (string) $older->id;
                if (in_array($olderId, $toDelete, true)) {
                    continue;
                }

                if ($newer->event_type === $older->event_type &&
                    $this->scorer->contentSimilarity($newer->content, $older->content) > $threshold) {

                    // Merge into newer: bump importance and access count
                    $newer->update([
                        'importance' => min(1.0, $newer->importance + ($older->importance * 0.2)),
                        'access_count' => $newer->access_count + $older->access_count,
                    ]);

                    $toDelete[] = $olderId;
                    $merged++;
                }
            }
        }

        if (! empty($toDelete)) {
            Memory::whereIn('id', $toDelete)->delete();
        }

        return $merged;
    }
}

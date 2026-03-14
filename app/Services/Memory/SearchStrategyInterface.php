<?php

namespace App\Services\Memory;

use App\Enums\ChannelEnum;
use Illuminate\Support\Collection;

/**
 * Interface for memory search strategies.
 *
 * Allows switching between different search backends
 * (Scout with Meilisearch, Database, etc.) without code changes.
 */
interface SearchStrategyInterface
{
    /**
     * Search episodic memories.
     *
     * @return Collection<int, \App\Models\Memory>
     */
    public function search(string $senderId, ChannelEnum $channel, string $query, int $limit): Collection;

    /**
     * Get the strategy name for logging/debugging.
     */
    public function getName(): string;
}

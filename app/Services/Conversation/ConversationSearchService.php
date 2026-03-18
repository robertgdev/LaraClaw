<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\Models\Conversation;
use App\Models\ConversationMessage;

/**
 * Handles searching conversations using Laravel Scout or database fallback.
 *
 * Extracted from the Conversation model to separate search orchestration
 * (driver detection, query building, fallback logic) from the Eloquent model.
 *
 * The Conversation model retains a backward-compatible static proxy
 * (searchConversations) that delegates here.
 */
class ConversationSearchService
{
    /**
     * Search conversations using Laravel Scout or fallback to LIKE search.
     *
     * @param  string  $query  Search query
     * @param  int  $limit  Maximum results
     * @param  string|null  $teamId  Optional team filter
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Conversation>|\Laravel\Scout\Builder<\App\Models\Conversation>
     */
    public function search(string $query, int $limit = 20, ?string $teamId = null)
    {
        // Check if Scout is using a real search driver (not database/collection)
        $scoutDriver = config('scout.driver');
        $usesScout = in_array($scoutDriver, ['algolia', 'meilisearch', 'typesense']);

        if ($usesScout) {
            // Use Scout for advanced search
            $searchQuery = Conversation::search($query);

            if ($teamId) {
                $searchQuery->where('team_id', $teamId);
            }

            return $searchQuery->take($limit);
        }

        // Fallback: search through messages and get distinct conversations
        $conversationIds = ConversationMessage::where('message', 'LIKE', "%{$query}%")
            ->pluck('conversation_id')
            ->unique()
            ->take($limit);

        $searchQuery = Conversation::query()
            ->whereIn('conversation_id', $conversationIds)
            ->orderBy('created_at', 'desc');

        if ($teamId) {
            $searchQuery->where('team_id', $teamId);
        }

        return $searchQuery;
    }
}

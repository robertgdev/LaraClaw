<?php

namespace App\Services\Memory;

use App\DTOs\ContextItemDTO;
use App\DTOs\SummaryRecordDTO;
use App\Helpers\TokenEstimatorHelper;
use App\Models\ContextItem;
use App\Models\ConversationMessage;
use App\Models\Summary;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Summary Store service.
 *
 * Handles persistence and retrieval of summaries and context items
 * for the lossless memory compaction system.
 */
class SummaryStore
{
    /**
     * Insert a new summary.
     *
     * @param  array  $input  Summary data
     * @return SummaryRecordDTO The created summary
     */
    public function insertSummary(array $input): SummaryRecordDTO
    {
        $summary = Summary::create([
            'summary_id' => $input['summary_id'],
            'conversation_id' => $input['conversation_id'],
            'kind' => $input['kind'],
            'depth' => $input['depth'] ?? ($input['kind'] === 'leaf' ? 0 : 1),
            'content' => $input['content'],
            'token_count' => $input['token_count'],
            'earliest_at' => $input['earliest_at'] ?? null,
            'latest_at' => $input['latest_at'] ?? null,
            'descendant_count' => $input['descendant_count'] ?? 0,
            'descendant_token_count' => $input['descendant_token_count'] ?? 0,
            'source_message_token_count' => $input['source_message_token_count'] ?? 0,
            'file_ids' => $input['file_ids'] ?? [],
        ]);

        return SummaryRecordDTO::fromModel($summary);
    }

    /**
     * Get a summary by ID.
     */
    public function getSummary(string $summaryId): ?SummaryRecordDTO
    {
        $summary = Summary::find($summaryId);

        return $summary ? SummaryRecordDTO::fromModel($summary) : null;
    }

    /**
     * Get all summaries for a conversation.
     *
     * @return array<SummaryRecordDTO>
     */
    public function getSummariesByConversation(int $conversationId): array
    {
        $summaries = Summary::forConversation($conversationId)
            ->orderBy('created_at')
            ->get();

        return $summaries->map(fn ($s) => SummaryRecordDTO::fromModel($s))->all();
    }

    /**
     * Get context items for a conversation.
     *
     * @return array<ContextItemDTO>
     */
    public function getContextItems(int $conversationId): array
    {
        $items = ContextItem::forConversation($conversationId)
            ->ordered()
            ->get();

        return $items->map(fn ($item) => ContextItemDTO::fromModel($item))->all();
    }

    /**
     * Get distinct depth levels in context.
     *
     * @return array<int>
     */
    public function getDistinctDepthsInContext(int $conversationId, ?int $maxOrdinalExclusive = null): array
    {
        $query = DB::table('context_items')
            ->join('summaries', 'summaries.summary_id', '=', 'context_items.summary_id')
            ->where('context_items.conversation_id', $conversationId)
            ->where('context_items.item_type', 'summary')
            ->distinct()
            ->orderBy('summaries.depth');

        if ($maxOrdinalExclusive !== null && $maxOrdinalExclusive !== PHP_INT_MAX) {
            $query->where('context_items.ordinal', '<', $maxOrdinalExclusive);
        }

        return $query->pluck('summaries.depth')->toArray();
    }

    /**
     * Get total token count for context.
     */
    public function getContextTokenCount(int $conversationId): int
    {
        // Get message tokens by estimating from content
        $messageIds = DB::table('context_items')
            ->where('conversation_id', $conversationId)
            ->where('item_type', 'message')
            ->pluck('message_id');

        $messageTokens = 0;
        foreach ($messageIds as $messageId) {
            $message = ConversationMessage::find($messageId);
            if ($message) {
                $messageTokens += $this->estimateTokens($message->message ?? '');
            }
        }

        // Get summary tokens from stored token_count
        $summaryTokens = DB::table('context_items')
            ->join('summaries', 'summaries.summary_id', '=', 'context_items.summary_id')
            ->where('context_items.conversation_id', $conversationId)
            ->where('context_items.item_type', 'summary')
            ->sum('summaries.token_count');

        return (int) ($messageTokens + $summaryTokens);
    }

    /**
     * Estimate token count from content.
     */
    private function estimateTokens(string $content): int
    {
        return TokenEstimatorHelper::estimate($content);
    }

    /**
     * Append a message to context.
     */
    public function appendContextMessage(int $conversationId, int $messageId): void
    {
        $maxOrdinal = ContextItem::forConversation($conversationId)
            ->max('ordinal') ?? -1;

        ContextItem::create([
            'conversation_id' => $conversationId,
            'ordinal' => $maxOrdinal + 1,
            'item_type' => 'message',
            'message_id' => $messageId,
            'summary_id' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * Append multiple messages to context.
     *
     * @param  array<int>  $messageIds
     */
    public function appendContextMessages(int $conversationId, array $messageIds): void
    {
        if (empty($messageIds)) {
            return;
        }

        $maxOrdinal = ContextItem::forConversation($conversationId)
            ->max('ordinal') ?? -1;

        foreach ($messageIds as $index => $messageId) {
            ContextItem::create([
                'conversation_id' => $conversationId,
                'ordinal' => $maxOrdinal + $index + 1,
                'item_type' => 'message',
                'message_id' => $messageId,
                'summary_id' => null,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Append a summary to context.
     */
    public function appendContextSummary(int $conversationId, string $summaryId): void
    {
        $maxOrdinal = ContextItem::forConversation($conversationId)
            ->max('ordinal') ?? -1;

        ContextItem::create([
            'conversation_id' => $conversationId,
            'ordinal' => $maxOrdinal + 1,
            'item_type' => 'summary',
            'message_id' => null,
            'summary_id' => $summaryId,
            'created_at' => now(),
        ]);
    }

    /**
     * Replace a range of context items with a summary.
     */
    public function replaceContextRangeWithSummary(
        int $conversationId,
        int $startOrdinal,
        int $endOrdinal,
        string $summaryId
    ): void {
        DB::transaction(function () use ($conversationId, $startOrdinal, $endOrdinal, $summaryId) {
            // Delete items in range
            ContextItem::forConversation($conversationId)
                ->whereBetween('ordinal', [$startOrdinal, $endOrdinal])
                ->delete();

            // Insert summary at start ordinal
            ContextItem::create([
                'conversation_id' => $conversationId,
                'ordinal' => $startOrdinal,
                'item_type' => 'summary',
                'message_id' => null,
                'summary_id' => $summaryId,
                'created_at' => now(),
            ]);

            // Resequence ordinals to maintain contiguity
            $this->resequenceOrdinals($conversationId);
        });
    }

    /**
     * Resequence ordinals to be contiguous.
     */
    private function resequenceOrdinals(int $conversationId): void
    {
        $items = ContextItem::forConversation($conversationId)
            ->ordered()
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        // Build a map of current ordinal to new ordinal
        $updates = [];
        foreach ($items as $index => $item) {
            if ($item->ordinal !== $index) {
                $updates[] = [
                    'conversation_id' => $item->conversation_id,
                    'old_ordinal' => $item->ordinal,
                    'new_ordinal' => $index,
                ];
            }
        }

        if (empty($updates)) {
            return;
        }

        // Use raw SQL to bypass the unique constraint temporarily
        // First, set all ordinals to large values to free up the constraint
        $offset = 1000000;
        foreach ($updates as $update) {
            DB::table('context_items')
                ->where('conversation_id', $update['conversation_id'])
                ->where('ordinal', $update['old_ordinal'])
                ->update(['ordinal' => $offset + $update['old_ordinal']]);
        }

        // Now set the correct ordinals
        foreach ($updates as $update) {
            DB::table('context_items')
                ->where('conversation_id', $update['conversation_id'])
                ->where('ordinal', $offset + $update['old_ordinal'])
                ->update(['ordinal' => $update['new_ordinal']]);
        }
    }

    /**
     * Link a summary to its source messages.
     *
     * @param  string  $summaryId  Summary ID
     * @param  array<int>  $messageIds  Message IDs
     */
    public function linkSummaryToMessages(string $summaryId, array $messageIds): void
    {
        if (empty($messageIds)) {
            return;
        }

        foreach ($messageIds as $index => $messageId) {
            DB::table('summary_messages')->updateOrInsert(
                ['summary_id' => $summaryId, 'message_id' => $messageId],
                ['ordinal' => $index]
            );
        }
    }

    /**
     * Link a summary to its parent summaries.
     *
     * @param  string  $summaryId  Summary ID
     * @param  array<string>  $parentSummaryIds  Parent summary IDs
     */
    public function linkSummaryToParents(string $summaryId, array $parentSummaryIds): void
    {
        if (empty($parentSummaryIds)) {
            return;
        }

        foreach ($parentSummaryIds as $index => $parentId) {
            DB::table('summary_parents')->updateOrInsert(
                ['summary_id' => $summaryId, 'parent_summary_id' => $parentId],
                ['ordinal' => $index]
            );
        }
    }

    /**
     * Get message IDs for a summary.
     *
     * @return array<int>
     */
    public function getSummaryMessages(string $summaryId): array
    {
        return DB::table('summary_messages')
            ->where('summary_id', $summaryId)
            ->orderBy('ordinal')
            ->pluck('message_id')
            ->toArray();
    }

    /**
     * Get parent summaries for a summary.
     *
     * @return array<SummaryRecordDTO>
     */
    public function getSummaryParents(string $summaryId): array
    {
        // Get parent summary IDs from the pivot table with ordering
        $parentIds = DB::table('summary_parents')
            ->where('summary_id', $summaryId)
            ->orderBy('ordinal')
            ->pluck('parent_summary_id');

        $parents = [];
        foreach ($parentIds as $parentId) {
            $summary = Summary::find($parentId);
            if ($summary) {
                $parents[] = SummaryRecordDTO::fromModel($summary);
            }
        }

        return $parents;
    }

    /**
     * Get child summaries for a summary.
     *
     * @return array<SummaryRecordDTO>
     */
    public function getSummaryChildren(string $parentSummaryId): array
    {
        // Get child summary IDs from the pivot table with ordering
        $childIds = DB::table('summary_parents')
            ->where('parent_summary_id', $parentSummaryId)
            ->orderBy('ordinal')
            ->pluck('summary_id');

        $children = [];
        foreach ($childIds as $childId) {
            $summary = Summary::find($childId);
            if ($summary) {
                $children[] = SummaryRecordDTO::fromModel($summary);
            }
        }

        return $children;
    }

    /**
     * Get max ordinal for a conversation.
     */
    public function getMaxOrdinal(int $conversationId): int
    {
        return ContextItem::forConversation($conversationId)
            ->max('ordinal') ?? -1;
    }
}

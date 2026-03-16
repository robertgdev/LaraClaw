<?php

namespace App\Services\Memory;

use App\DTOs\IntegrityCheckDTO;
use App\DTOs\IntegrityReportDTO;
use App\Helpers\TokenEstimatorHelper;
use App\Models\ContextItem;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Summary;
use Illuminate\Support\Facades\DB;

/**
 * Integrity Checker service.
 *
 * Validates the integrity of the lossless memory system by checking:
 * - Context items are contiguous
 * - All references are valid
 * - Summaries have proper lineage
 * - No orphaned summaries
 * - Token counts are consistent
 */
class IntegrityChecker
{
    private SummaryStore $summaryStore;

    public function __construct(SummaryStore $summaryStore)
    {
        $this->summaryStore = $summaryStore;
    }

    /**
     * Run all integrity checks for a conversation.
     */
    public function scan(int $conversationId): IntegrityReportDTO
    {
        $checks = [];

        // 1. Conversation exists
        $checks[] = $this->checkConversationExists($conversationId);

        // 2. Context items contiguous
        $checks[] = $this->checkContextItemsContiguous($conversationId);

        // 3. Context items valid refs
        $checks[] = $this->checkContextItemsValidRefs($conversationId);

        // 4. Summaries have lineage
        $checks[] = $this->checkSummariesHaveLineage($conversationId);

        // 5. No orphan summaries
        $checks[] = $this->checkNoOrphanSummaries($conversationId);

        // 6. Context token consistency
        $checks[] = $this->checkContextTokenConsistency($conversationId);

        // 7. Message seq contiguous
        $checks[] = $this->checkMessageSeqContiguous($conversationId);

        // 8. No duplicate context refs
        $checks[] = $this->checkNoDuplicateContextRefs($conversationId);

        return IntegrityReportDTO::fromChecks($conversationId, $checks);
    }

    /**
     * Check that the conversation exists.
     */
    private function checkConversationExists(int $conversationId): IntegrityCheckDTO
    {
        $conversation = Conversation::find($conversationId);

        if ($conversation) {
            return IntegrityCheckDTO::pass(
                'conversation_exists',
                "Conversation {$conversationId} exists"
            );
        }

        return IntegrityCheckDTO::fail(
            'conversation_exists',
            "Conversation {$conversationId} not found"
        );
    }

    /**
     * Check that context items have contiguous ordinals.
     */
    private function checkContextItemsContiguous(int $conversationId): IntegrityCheckDTO
    {
        $items = ContextItem::forConversation($conversationId)
            ->ordered()
            ->get();

        if ($items->isEmpty()) {
            return IntegrityCheckDTO::pass(
                'context_items_contiguous',
                'No context items to check'
            );
        }

        $gaps = [];
        foreach ($items as $index => $item) {
            if ($item->ordinal !== $index) {
                $gaps[] = ['expected' => $index, 'actual' => $item->ordinal];
            }
        }

        if (empty($gaps)) {
            return IntegrityCheckDTO::pass(
                'context_items_contiguous',
                "All {$items->count()} context items have contiguous ordinals"
            );
        }

        return IntegrityCheckDTO::fail(
            'context_items_contiguous',
            'Found '.count($gaps).' ordinal gap(s) in context items',
            ['gaps' => $gaps]
        );
    }

    /**
     * Check that all context item references are valid.
     */
    private function checkContextItemsValidRefs(int $conversationId): IntegrityCheckDTO
    {
        $items = ContextItem::forConversation($conversationId)
            ->ordered()
            ->get();

        $danglingRefs = [];

        foreach ($items as $item) {
            if ($item->item_type === 'message' && $item->message_id !== null) {
                $message = ConversationMessage::find($item->message_id);
                if (! $message) {
                    $danglingRefs[] = [
                        'ordinal' => $item->ordinal,
                        'item_type' => 'message',
                        'ref_id' => $item->message_id,
                    ];
                }
            } elseif ($item->item_type === 'summary' && $item->summary_id !== null) {
                $summary = Summary::find($item->summary_id);
                if (! $summary) {
                    $danglingRefs[] = [
                        'ordinal' => $item->ordinal,
                        'item_type' => 'summary',
                        'ref_id' => $item->summary_id,
                    ];
                }
            }
        }

        if (empty($danglingRefs)) {
            return IntegrityCheckDTO::pass(
                'context_items_valid_refs',
                'All context item references are valid'
            );
        }

        return IntegrityCheckDTO::fail(
            'context_items_valid_refs',
            'Found '.count($danglingRefs).' dangling reference(s) in context items',
            ['dangling_refs' => $danglingRefs]
        );
    }

    /**
     * Check that summaries have proper lineage.
     */
    private function checkSummariesHaveLineage(int $conversationId): IntegrityCheckDTO
    {
        $summaries = Summary::forConversation($conversationId)->get();
        $missingLineage = [];

        foreach ($summaries as $summary) {
            if ($summary->kind === 'leaf') {
                // Leaf summaries must link to at least one message
                $messageIds = $this->summaryStore->getSummaryMessages($summary->summary_id);
                if (empty($messageIds)) {
                    $missingLineage[] = [
                        'summary_id' => $summary->summary_id,
                        'kind' => 'leaf',
                        'issue' => 'no linked messages in summary_messages',
                    ];
                }
            } elseif ($summary->kind === 'condensed') {
                // Condensed summaries must link to at least one parent summary
                $parents = $this->summaryStore->getSummaryParents($summary->summary_id);
                if (empty($parents)) {
                    $missingLineage[] = [
                        'summary_id' => $summary->summary_id,
                        'kind' => 'condensed',
                        'issue' => 'no linked parents in summary_parents',
                    ];
                }
            }
        }

        if (empty($missingLineage)) {
            return IntegrityCheckDTO::pass(
                'summaries_have_lineage',
                "All {$summaries->count()} summaries have proper lineage"
            );
        }

        return IntegrityCheckDTO::fail(
            'summaries_have_lineage',
            'Found '.count($missingLineage).' summary/summaries missing lineage',
            ['missing_lineage' => $missingLineage]
        );
    }

    /**
     * Check for orphaned summaries.
     */
    private function checkNoOrphanSummaries(int $conversationId): IntegrityCheckDTO
    {
        $summaries = Summary::forConversation($conversationId)->get();
        $contextItems = ContextItem::forConversation($conversationId)->get();

        // Build set of summary IDs in context_items
        $contextSummaryIds = $contextItems
            ->filter(fn ($item) => $item->item_type === 'summary' && $item->summary_id !== null)
            ->pluck('summary_id')
            ->toArray();

        // Build set of summary IDs that are parents of other summaries
        $parentSummaryIds = [];
        foreach ($summaries as $summary) {
            $children = $this->summaryStore->getSummaryChildren($summary->summary_id);
            if (! empty($children)) {
                $parentSummaryIds[] = $summary->summary_id;
            }
        }

        // Orphans are summaries in neither set
        $orphans = [];
        foreach ($summaries as $summary) {
            if (! in_array($summary->summary_id, $contextSummaryIds) &&
                ! in_array($summary->summary_id, $parentSummaryIds)) {
                $orphans[] = $summary->summary_id;
            }
        }

        if (empty($orphans)) {
            return IntegrityCheckDTO::pass(
                'no_orphan_summaries',
                'No orphaned summaries found'
            );
        }

        return IntegrityCheckDTO::warn(
            'no_orphan_summaries',
            'Found '.count($orphans).' orphaned summary/summaries disconnected from the DAG',
            ['orphaned_summary_ids' => $orphans]
        );
    }

    /**
     * Check context token consistency.
     */
    private function checkContextTokenConsistency(int $conversationId): IntegrityCheckDTO
    {
        $contextItems = ContextItem::forConversation($conversationId)
            ->ordered()
            ->get();

        // Manually sum token counts
        $manualSum = 0;
        foreach ($contextItems as $item) {
            if ($item->item_type === 'message' && $item->message_id !== null) {
                $message = ConversationMessage::find($item->message_id);
                if ($message) {
                    $manualSum += $this->estimateTokens($message->message ?? '');
                }
            } elseif ($item->item_type === 'summary' && $item->summary_id !== null) {
                $summary = Summary::find($item->summary_id);
                if ($summary) {
                    $manualSum += $summary->token_count;
                }
            }
        }

        // Compare with aggregate query
        $aggregateTotal = $this->summaryStore->getContextTokenCount($conversationId);

        if ($manualSum === $aggregateTotal) {
            return IntegrityCheckDTO::pass(
                'context_token_consistency',
                "Context token count is consistent ({$aggregateTotal} tokens)"
            );
        }

        return IntegrityCheckDTO::fail(
            'context_token_consistency',
            "Token count mismatch: item-level sum = {$manualSum}, aggregate query = {$aggregateTotal}",
            ['manual_sum' => $manualSum, 'aggregate_total' => $aggregateTotal, 'difference' => $manualSum - $aggregateTotal]
        );
    }

    /**
     * Check that message sequence is contiguous.
     */
    private function checkMessageSeqContiguous(int $conversationId): IntegrityCheckDTO
    {
        $conversation = Conversation::find($conversationId);
        if (! $conversation) {
            return IntegrityCheckDTO::fail(
                'message_seq_contiguous',
                'Conversation not found'
            );
        }

        $messages = $conversation->messages()->orderBy('created_at')->get();

        if ($messages->isEmpty()) {
            return IntegrityCheckDTO::pass(
                'message_seq_contiguous',
                'No messages to check'
            );
        }

        // Check for gaps in message order (based on created_at)
        $gaps = [];
        $prevCreatedAt = null;
        $index = 0;
        foreach ($messages as $message) {
            if ($prevCreatedAt !== null && $message->created_at < $prevCreatedAt) {
                $gaps[] = ['index' => $index, 'issue' => 'out_of_order'];
            }
            $prevCreatedAt = $message->created_at;
            $index++;
        }

        if (empty($gaps)) {
            return IntegrityCheckDTO::pass(
                'message_seq_contiguous',
                "All {$messages->count()} messages are in chronological order"
            );
        }

        return IntegrityCheckDTO::fail(
            'message_seq_contiguous',
            'Found '.count($gaps).' ordering issue(s) in messages',
            ['gaps' => $gaps]
        );
    }

    /**
     * Check for duplicate context references.
     */
    private function checkNoDuplicateContextRefs(int $conversationId): IntegrityCheckDTO
    {
        $items = ContextItem::forConversation($conversationId)
            ->ordered()
            ->get();

        $seenMessageIds = [];
        $seenSummaryIds = [];
        $duplicates = [];

        foreach ($items as $item) {
            if ($item->item_type === 'message' && $item->message_id !== null) {
                if (isset($seenMessageIds[$item->message_id])) {
                    $seenMessageIds[$item->message_id][] = $item->ordinal;
                } else {
                    $seenMessageIds[$item->message_id] = [$item->ordinal];
                }
            } elseif ($item->item_type === 'summary' && $item->summary_id !== null) {
                if (isset($seenSummaryIds[$item->summary_id])) {
                    $seenSummaryIds[$item->summary_id][] = $item->ordinal;
                } else {
                    $seenSummaryIds[$item->summary_id] = [$item->ordinal];
                }
            }
        }

        foreach ($seenMessageIds as $messageId => $ordinals) {
            if (count($ordinals) > 1) {
                $duplicates[] = ['ref_type' => 'message', 'ref_id' => $messageId, 'ordinals' => $ordinals];
            }
        }

        foreach ($seenSummaryIds as $summaryId => $ordinals) {
            if (count($ordinals) > 1) {
                $duplicates[] = ['ref_type' => 'summary', 'ref_id' => $summaryId, 'ordinals' => $ordinals];
            }
        }

        if (empty($duplicates)) {
            return IntegrityCheckDTO::pass(
                'no_duplicate_context_refs',
                'No duplicate references in context items'
            );
        }

        return IntegrityCheckDTO::fail(
            'no_duplicate_context_refs',
            'Found '.count($duplicates).' duplicate reference(s) in context items',
            ['duplicates' => $duplicates]
        );
    }

    /**
     * Estimate token count from content.
     */
    private function estimateTokens(string $content): int
    {
        return TokenEstimatorHelper::estimate($content);
    }

    /**
     * Generate repair suggestions for a report.
     *
     * @return array<string>
     */
    public static function repairPlan(IntegrityReportDTO $report): array
    {
        $suggestions = [];

        foreach ($report->checks as $check) {
            if ($check->isPass()) {
                continue;
            }

            switch ($check->name) {
                case 'conversation_exists':
                    $suggestions[] = "Create or restore conversation {$report->conversationId} in the conversations table";
                    break;

                case 'context_items_contiguous':
                    $suggestions[] = 'Resequence context items to fix ordinal gaps';
                    break;

                case 'context_items_valid_refs':
                    $details = $check->details;
                    if (isset($details['dangling_refs'])) {
                        foreach ($details['dangling_refs'] as $ref) {
                            $suggestions[] = "Remove context item at ordinal {$ref['ordinal']} referencing missing {$ref['item_type']} {$ref['ref_id']}";
                        }
                    } else {
                        $suggestions[] = 'Remove context items with dangling references';
                    }
                    break;

                case 'summaries_have_lineage':
                    $details = $check->details;
                    if (isset($details['missing_lineage'])) {
                        foreach ($details['missing_lineage'] as $entry) {
                            if ($entry['kind'] === 'leaf') {
                                $suggestions[] = "Add missing lineage for leaf summary {$entry['summary_id']} (link to source messages via summary_messages)";
                            } else {
                                $suggestions[] = "Add missing lineage for condensed summary {$entry['summary_id']} (link to parent summaries via summary_parents)";
                            }
                        }
                    } else {
                        $suggestions[] = 'Add missing lineage links for summaries';
                    }
                    break;

                case 'no_orphan_summaries':
                    $details = $check->details;
                    if (isset($details['orphaned_summary_ids'])) {
                        foreach ($details['orphaned_summary_ids'] as $id) {
                            $suggestions[] = "Remove orphaned summary {$id} from summaries table";
                        }
                    } else {
                        $suggestions[] = 'Remove orphaned summaries disconnected from the DAG';
                    }
                    break;

                case 'context_token_consistency':
                    $suggestions[] = 'Recompute context token count to reconcile mismatch between item-level sum and aggregate query';
                    break;

                case 'message_seq_contiguous':
                    $suggestions[] = 'Resequence message order to eliminate gaps (renumber starting from 0)';
                    break;

                case 'no_duplicate_context_refs':
                    $details = $check->details;
                    if (isset($details['duplicates'])) {
                        foreach ($details['duplicates'] as $dup) {
                            $keepOrdinal = $dup['ordinals'][0];
                            $removeOrdinals = implode(', ', array_slice($dup['ordinals'], 1));
                            $suggestions[] = "Deduplicate {$dup['ref_type']} {$dup['ref_id']}: keep ordinal {$keepOrdinal}, remove ordinals {$removeOrdinals}";
                        }
                    } else {
                        $suggestions[] = 'Remove duplicate message_id or summary_id references from context items';
                    }
                    break;

                default:
                    $suggestions[] = "Address failing check: {$check->name} -- {$check->message}";
                    break;
            }
        }

        return $suggestions;
    }
}

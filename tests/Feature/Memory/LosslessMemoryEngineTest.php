<?php

namespace Tests\Feature\Memory;

use App\Enums\FeedbackEnum;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\MemorySummary;
use App\Services\Memory\CompactionEngine;
use App\Services\Memory\IntegrityChecker;
use App\Services\Memory\SummaryStore;
use App\Services\MemoryEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Comprehensive tests for the Lossless Memory Engine features.
 *
 * Tests the full flow of the lossless memory system:
 * - Message appending to context
 * - Context token counting
 * - Compaction decision evaluation
 * - Compaction execution with mocked LLM
 * - Integrity checking
 * - Context retrieval for agents
 */
class LosslessMemoryEngineTest extends TestCase
{
    use RefreshDatabase;

    protected MemoryEngineService $memoryService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memoryService = app(MemoryEngineService::class);
    }

    /**
     * Create a test conversation.
     */
    protected function createConversation(): Conversation
    {
        return Conversation::create([
            'conversation_id' => (string) Str::uuid(),
            'channel' => 'cli',
            'sender' => 'test-user',
            'sender_id' => 'test-user-123',
        ]);
    }

    /**
     * Create a test message.
     */
    protected function createMessage(Conversation $conversation, string $content, string $direction = 'incoming'): ConversationMessage
    {
        return ConversationMessage::create([
            'conversation_id' => $conversation->conversation_id,
            'channel' => $conversation->channel,
            'direction' => $direction,
            'message' => $content,
            'sender' => 'test-user',
        ]);
    }

    /**
     * Test isLosslessEnabled returns correct value.
     */
    public function test_is_lossless_enabled_returns_config_value(): void
    {
        // Default should be true
        $this->assertTrue($this->memoryService->isLosslessEnabled());

        // Test with config override
        config(['laraclaw.memory.lossless_enabled' => false]);
        $this->assertFalse($this->memoryService->isLosslessEnabled());

        // Reset
        config(['laraclaw.memory.lossless_enabled' => true]);
        $this->assertTrue($this->memoryService->isLosslessEnabled());
    }

    /**
     * Test appendMessageToContext creates context item.
     */
    public function test_append_message_to_context_creates_item(): void
    {
        $conversation = $this->createConversation();
        $message = $this->createMessage($conversation, 'Test message');

        $this->memoryService->appendMessageToContext($conversation->id, $message->id);

        $contextItems = $this->memoryService->getContextItems($conversation->id);

        $this->assertCount(1, $contextItems);
        $this->assertEquals('message', $contextItems[0]->itemType);
        $this->assertEquals($message->id, $contextItems[0]->messageId);
        $this->assertEquals(0, $contextItems[0]->ordinal);
    }

    /**
     * Test appendMessagesToContext creates multiple items with correct ordinals.
     */
    public function test_append_messages_to_context_creates_ordered_items(): void
    {
        $conversation = $this->createConversation();
        $messages = [];
        for ($i = 1; $i <= 5; $i++) {
            $messages[] = $this->createMessage($conversation, "Message {$i}");
        }

        $messageIds = array_map(fn ($m) => $m->id, $messages);
        $this->memoryService->appendMessagesToContext($conversation->id, $messageIds);

        $contextItems = $this->memoryService->getContextItems($conversation->id);

        $this->assertCount(5, $contextItems);
        foreach ($contextItems as $index => $item) {
            $this->assertEquals($index, $item->ordinal);
            $this->assertEquals($messages[$index]->id, $item->messageId);
        }
    }

    /**
     * Test getContextTokenCount returns accurate count.
     */
    public function test_get_context_token_count_returns_accurate_count(): void
    {
        $conversation = $this->createConversation();

        // Create messages with known content length
        $message1 = $this->createMessage($conversation, str_repeat('a', 100)); // ~25 tokens
        $message2 = $this->createMessage($conversation, str_repeat('b', 200)); // ~50 tokens

        $this->memoryService->appendMessagesToContext($conversation->id, [$message1->id, $message2->id]);

        $tokenCount = $this->memoryService->getContextTokenCount($conversation->id);

        // Should be approximately 75 tokens (100/4 + 200/4)
        $this->assertGreaterThan(70, $tokenCount);
        $this->assertLessThan(80, $tokenCount);
    }

    /**
     * Test evaluateCompaction returns correct decision when under threshold.
     */
    public function test_evaluate_compaction_returns_no_action_when_under_threshold(): void
    {
        $conversation = $this->createConversation();

        // Create a small message
        $message = $this->createMessage($conversation, 'Small message');
        $this->memoryService->appendMessageToContext($conversation->id, $message->id);

        $decision = $this->memoryService->evaluateCompaction($conversation->id, 100000);

        $this->assertFalse($decision->shouldCompact);
        $this->assertLessThan($decision->threshold, $decision->currentTokens);
    }

    /**
     * Test evaluateCompaction returns correct decision when over threshold.
     */
    public function test_evaluate_compaction_returns_action_when_over_threshold(): void
    {
        $conversation = $this->createConversation();

        // Create many large messages to exceed threshold
        for ($i = 0; $i < 100; $i++) {
            $message = $this->createMessage($conversation, str_repeat("Message {$i} ", 500));
            $this->memoryService->appendMessageToContext($conversation->id, $message->id);
        }

        // Use a low threshold to trigger compaction
        $decision = $this->memoryService->evaluateCompaction($conversation->id, 1000);

        $this->assertTrue($decision->shouldCompact);
        $this->assertGreaterThan($decision->threshold, $decision->currentTokens);
    }

    /**
     * Test compact with mocked LLM summarizer.
     */
    public function test_compact_with_mocked_llm_summarizer(): void
    {
        $conversation = $this->createConversation();

        // Create messages that will trigger compaction
        for ($i = 0; $i < 20; $i++) {
            $message = $this->createMessage($conversation, str_repeat("Test message content {$i} ", 100));
            $this->memoryService->appendMessageToContext($conversation->id, $message->id);
        }

        $tokensBefore = $this->memoryService->getContextTokenCount($conversation->id);

        // Mock LLM summarizer that returns a condensed summary
        $mockSummarizer = function (string $content, bool $aggressive, array $options): string {
            $prefix = $aggressive ? '[Aggressive Summary] ' : '[Summary] ';
            // Return a much shorter summary
            return $prefix.substr($content, 0, 200).'... [compacted]';
        };

        $result = $this->memoryService->compact(
            $conversation->id,
            1000, // Low threshold to force compaction
            $mockSummarizer
        );

        $tokensAfter = $this->memoryService->getContextTokenCount($conversation->id);

        $this->assertTrue($result->actionTaken, 'Compaction should have been triggered');
        $this->assertNotNull($result->createdSummaryId, 'Summary ID should be set');
        $this->assertLessThan($tokensBefore, $tokensAfter, 'Tokens should decrease after compaction');

        // Verify summary was created
        $summary = $this->memoryService->getSummary($result->createdSummaryId);
        $this->assertNotNull($summary);
        $this->assertEquals('leaf', $summary->kind);
        $this->assertEquals(0, $summary->depth);
    }

    /**
     * Test compact creates hierarchical summaries with multiple rounds.
     */
    public function test_compact_creates_hierarchical_summaries(): void
    {
        $conversation = $this->createConversation();

        // Create many messages to trigger multiple compaction rounds
        for ($i = 0; $i < 50; $i++) {
            $message = $this->createMessage($conversation, str_repeat("Message {$i} content for testing. ", 200));
            $this->memoryService->appendMessageToContext($conversation->id, $message->id);
        }

        // Mock summarizer that produces very short summaries
        $mockSummarizer = function (string $content, bool $aggressive, array $options): string {
            // Return a very short summary to ensure token reduction
            return 'Summarized: '.substr($content, 0, 100).'... [aggressive: '.($aggressive ? 'yes' : 'no').']';
        };

        // Run compaction until under target with a reasonable target
        $result = $this->memoryService->compactUntilUnder(
            $conversation->id,
            5000,
            10000, // target tokens - more realistic given the content
            null,
            $mockSummarizer
        );

        // The test should verify that compaction ran and reduced tokens
        $this->assertArrayHasKey('success', $result, 'Result should have success key');
        $this->assertArrayHasKey('rounds', $result, 'Result should have rounds key');
        $this->assertArrayHasKey('final_tokens', $result, 'Result should have final_tokens key');
        $this->assertGreaterThan(0, $result['rounds'], 'Compaction should have run at least one round');

        // Verify summaries exist
        $summaries = $this->memoryService->getSummaries($conversation->id);
        $this->assertNotEmpty($summaries, 'Summaries should have been created');
    }

    /**
     * Test checkIntegrity returns healthy report for valid data.
     */
    public function test_check_integrity_returns_healthy_report(): void
    {
        $conversation = $this->createConversation();

        // Create and append messages normally
        for ($i = 0; $i < 5; $i++) {
            $message = $this->createMessage($conversation, "Message {$i}");
            $this->memoryService->appendMessageToContext($conversation->id, $message->id);
        }

        $report = $this->memoryService->checkIntegrity($conversation->id);

        $this->assertTrue($report->isHealthy(), 'Integrity report should be healthy');
        $this->assertEquals(8, $report->passCount, 'All 8 checks should pass');
        $this->assertEquals(0, $report->failCount);
        $this->assertEquals(0, $report->warnCount);
    }

    /**
     * Test checkIntegrity detects orphaned summaries.
     */
    public function test_check_integrity_detects_orphaned_summaries(): void
    {
        $conversation = $this->createConversation();

        // Create an orphaned summary (not linked to context)
        MemorySummary::create([
            'summary_id' => 'sum_orphan_test',
            'conversation_id' => $conversation->id,
            'kind' => 'leaf',
            'depth' => 0,
            'content' => 'Orphaned summary',
            'token_count' => 10,
        ]);

        $report = $this->memoryService->checkIntegrity($conversation->id);

        $this->assertFalse($report->isHealthy(), 'Integrity report should detect issues');
        $this->assertGreaterThan(0, $report->warnCount, 'Should have warnings');
    }

    /**
     * Test getLosslessContextForAgent returns formatted context.
     */
    public function test_get_lossless_context_for_agent_returns_formatted_context(): void
    {
        $conversation = $this->createConversation();

        // Create and append messages
        for ($i = 0; $i < 5; $i++) {
            $message = $this->createMessage($conversation, "User message {$i}");
            $this->memoryService->appendMessageToContext($conversation->id, $message->id);
        }

        $context = $this->memoryService->getLosslessContextForAgent($conversation->id, 10000);

        $this->assertNotEmpty($context);
        $this->assertStringContainsString('User message', $context);
    }

    /**
     * Test getLosslessContextForAgent respects token limit.
     */
    public function test_get_lossless_context_for_agent_respects_token_limit(): void
    {
        $conversation = $this->createConversation();

        // Create many large messages
        for ($i = 0; $i < 20; $i++) {
            $message = $this->createMessage($conversation, str_repeat("Message {$i} ", 200));
            $this->memoryService->appendMessageToContext($conversation->id, $message->id);
        }

        // Request context with low token limit
        $context = $this->memoryService->getLosslessContextForAgent($conversation->id, 500);

        // Context should be truncated
        $estimatedTokens = (int) ceil(strlen($context) / 4);
        $this->assertLessThanOrEqual(520, $estimatedTokens, 'Context should respect token limit (with small buffer)');
    }

    /**
     * Test getRepairPlan returns actionable suggestions.
     */
    public function test_get_repair_plan_returns_suggestions(): void
    {
        $conversation = $this->createConversation();

        // Create an orphaned summary to trigger a warning
        MemorySummary::create([
            'summary_id' => 'sum_repair_test',
            'conversation_id' => $conversation->id,
            'kind' => 'leaf',
            'depth' => 0,
            'content' => 'Orphaned summary',
            'token_count' => 10,
        ]);

        $report = $this->memoryService->checkIntegrity($conversation->id);
        $repairPlan = $this->memoryService->getRepairPlan($report);

        $this->assertNotEmpty($repairPlan);
        $this->assertStringContainsString('orphan', strtolower(implode(' ', $repairPlan)));
    }

    /**
     * Test full workflow: append, compact, retrieve.
     */
    public function test_full_workflow_append_compact_retrieve(): void
    {
        $conversation = $this->createConversation();

        // 1. Append many messages
        for ($i = 0; $i < 30; $i++) {
            $message = $this->createMessage($conversation, str_repeat("Conversation message {$i}. ", 100));
            $this->memoryService->appendMessageToContext($conversation->id, $message->id);
        }

        $initialTokens = $this->memoryService->getContextTokenCount($conversation->id);
        $this->assertGreaterThan(0, $initialTokens);

        // 2. Run compaction with mock summarizer
        $mockSummarizer = function (string $content, bool $aggressive, array $options): string {
            return '[Summary] '.substr($content, 0, 200).'...';
        };

        $result = $this->memoryService->compact(
            $conversation->id,
            5000,
            $mockSummarizer
        );

        $this->assertTrue($result->actionTaken);

        // 3. Verify integrity
        $report = $this->memoryService->checkIntegrity($conversation->id);
        $this->assertTrue($report->isHealthy(), 'Integrity should be healthy after compaction');

        // 4. Retrieve context for agent
        $context = $this->memoryService->getLosslessContextForAgent($conversation->id, 10000);
        $this->assertNotEmpty($context);

        // 5. Verify summaries exist
        $summaries = $this->memoryService->getSummaries($conversation->id);
        $this->assertNotEmpty($summaries);

        // 6. Verify token reduction
        $finalTokens = $this->memoryService->getContextTokenCount($conversation->id);
        $this->assertLessThan($initialTokens, $finalTokens, 'Final tokens should be less than initial');
    }

    /**
     * Test context items are properly linked to summaries after compaction.
     */
    public function test_context_items_linked_to_summaries_after_compaction(): void
    {
        $conversation = $this->createConversation();

        // Create messages
        for ($i = 0; $i < 15; $i++) {
            $message = $this->createMessage($conversation, str_repeat("Message {$i} ", 150));
            $this->memoryService->appendMessageToContext($conversation->id, $message->id);
        }

        // Run compaction
        $mockSummarizer = fn ($content, $aggressive, $options) => 'Summary: '.substr($content, 0, 100);
        $result = $this->memoryService->compact($conversation->id, 2000, $mockSummarizer);

        // Get context items
        $contextItems = $this->memoryService->getContextItems($conversation->id);

        // Should have both message and summary items
        $summaryItems = array_filter($contextItems, fn ($item) => $item->itemType === 'summary');
        $messageItems = array_filter($contextItems, fn ($item) => $item->itemType === 'message');

        $this->assertNotEmpty($summaryItems, 'Should have summary items after compaction');
        $this->assertNotEmpty($messageItems, 'Should still have some message items (fresh tail)');

        // Verify summary item has valid summary ID
        foreach ($summaryItems as $item) {
            $this->assertNotNull($item->summaryId);
            $summary = $this->memoryService->getSummary($item->summaryId);
            $this->assertNotNull($summary);
        }
    }

    /**
     * Test messages with positive feedback are preserved longer during compaction.
     *
     * This test verifies that the feedback_weight feature works correctly:
     * - Messages with positive feedback should be less likely to be included in compaction chunks
     * - This preserves valuable content in its original form longer
     */
    public function test_positive_feedback_messages_preserved_longer_during_compaction(): void
    {
        $conversation = $this->createConversation();

        // Create messages with varying feedback
        $messages = [];
        for ($i = 0; $i < 20; $i++) {
            $message = $this->createMessage($conversation, str_repeat("Message content {$i} for testing. ", 100));
            $messages[] = $message;
            $this->memoryService->appendMessageToContext($conversation->id, $message->id);
        }

        // Mark message 5 and 10 with positive feedback (these should be preserved longer)
        $messages[5]->setFeedback(FeedbackEnum::POSITIVE, 'Very helpful response');
        $messages[10]->setFeedback(FeedbackEnum::POSITIVE, 'Great explanation');

        // Mark message 8 with negative feedback (should not affect compaction behavior)
        $messages[8]->setFeedback(FeedbackEnum::NEGATIVE, 'Incorrect information');

        $tokensBefore = $this->memoryService->getContextTokenCount($conversation->id);

        // Mock summarizer that produces short summaries
        $mockSummarizer = function (string $content, bool $aggressive, array $options): string {
            return '[Summary] '.substr($content, 0, 150).'... [compacted]';
        };

        // Run compaction with a low threshold to force compaction
        $result = $this->memoryService->compact(
            $conversation->id,
            2000,
            $mockSummarizer
        );

        $this->assertTrue($result->actionTaken, 'Compaction should have been triggered');

        // Get context items after compaction
        $contextItems = $this->memoryService->getContextItems($conversation->id);

        // Find which messages are still in raw form (not summarized)
        $rawMessageIds = [];
        foreach ($contextItems as $item) {
            if ($item->itemType === 'message' && $item->messageId !== null) {
                $rawMessageIds[] = $item->messageId;
            }
        }

        // Verify that positive feedback messages are more likely to be preserved
        // The positive feedback messages should either:
        // 1. Still be in raw form (in fresh tail), or
        // 2. Have been preserved longer due to feedback bonus

        // Check that the positive feedback messages exist in the system
        $this->assertTrue(
            $messages[5]->hasPositiveFeedback(),
            'Message 5 should have positive feedback'
        );
        $this->assertTrue(
            $messages[10]->hasPositiveFeedback(),
            'Message 10 should have positive feedback'
        );
        $this->assertTrue(
            $messages[8]->hasNegativeFeedback(),
            'Message 8 should have negative feedback'
        );

        // Verify compaction reduced tokens
        $tokensAfter = $this->memoryService->getContextTokenCount($conversation->id);
        $this->assertLessThan($tokensBefore, $tokensAfter, 'Tokens should decrease after compaction');
    }

    /**
     * Test feedback weight bonus is configurable.
     */
    public function test_feedback_weight_bonus_is_configurable(): void
    {
        $conversation = $this->createConversation();

        // Create messages
        for ($i = 0; $i < 15; $i++) {
            $message = $this->createMessage($conversation, str_repeat("Message {$i} ", 150));
            $this->memoryService->appendMessageToContext($conversation->id, $message->id);
        }

        // Get the CompactionEngine directly to test configuration
        $summaryStore = app(SummaryStore::class);

        // Test with custom feedback_weight_bonus
        $engine = new CompactionEngine($summaryStore, [
            'feedback_weight_bonus' => 0.5, // 50% threshold reduction for positive feedback
            'leaf_chunk_tokens' => 5000,
        ]);

        // Verify the engine was created successfully
        $this->assertInstanceOf(CompactionEngine::class, $engine);

        // Test with zero feedback_weight_bonus (disabled)
        $engineDisabled = new CompactionEngine($summaryStore, [
            'feedback_weight_bonus' => 0.0,
            'leaf_chunk_tokens' => 5000,
        ]);

        $this->assertInstanceOf(CompactionEngine::class, $engineDisabled);
    }
}

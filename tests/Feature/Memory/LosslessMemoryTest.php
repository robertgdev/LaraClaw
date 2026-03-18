<?php

namespace Tests\Feature\Memory;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\MemoryContextItem;
use App\Models\MemorySummary;
use App\Services\Memory\CompactionEngine;
use App\Services\Memory\IntegrityChecker;
use App\Services\Memory\SummaryStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LosslessMemoryTest extends TestCase
{
    use RefreshDatabase;

    protected SummaryStore $summaryStore;

    protected CompactionEngine $compactionEngine;

    protected IntegrityChecker $integrityChecker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->summaryStore = app(SummaryStore::class);
        $this->compactionEngine = app(CompactionEngine::class);
        $this->integrityChecker = app(IntegrityChecker::class);
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
    protected function createMessage(Conversation $conversation, string $content): ConversationMessage
    {
        return ConversationMessage::create([
            'conversation_id' => $conversation->conversation_id,
            'channel' => $conversation->channel,
            'direction' => 'incoming',
            'message' => $content,
            'sender' => 'test-user',
        ]);
    }

    /**
     * Test basic summary creation.
     */
    public function test_summary_creation(): void
    {
        $conversation = $this->createConversation();

        $summary = MemorySummary::create([
            'summary_id' => 'sum_test123',
            'conversation_id' => $conversation->id,
            'kind' => 'leaf',
            'depth' => 0,
            'content' => 'Test summary content',
            'token_count' => 100,
        ]);

        $this->assertNotNull($summary->summary_id);
        $this->assertEquals('leaf', $summary->kind);
        $this->assertEquals(0, $summary->depth);
        $this->assertEquals(100, $summary->token_count);

        // Test retrieval
        $retrieved = $this->summaryStore->getSummary($summary->summary_id);
        $this->assertNotNull($retrieved);
        $this->assertEquals($summary->summary_id, $retrieved->summaryId);
    }

    /**
     * Test context item creation.
     */
    public function test_context_item_creation(): void
    {
        $conversation = $this->createConversation();

        // Create a message
        $message = $this->createMessage($conversation, 'Test message content');

        // Append to context
        $this->summaryStore->appendContextMessage($conversation->id, $message->id);

        $contextItems = $this->summaryStore->getContextItems($conversation->id);

        $this->assertCount(1, $contextItems);
        $this->assertEquals('message', $contextItems[0]->itemType);
        $this->assertEquals($message->id, $contextItems[0]->messageId);
    }

    /**
     * Test context token count.
     */
    public function test_context_token_count(): void
    {
        $conversation = $this->createConversation();

        // Create messages
        $messageIds = [];
        for ($i = 1; $i <= 3; $i++) {
            $message = $this->createMessage($conversation, str_repeat("Test message content {$i} ", 100));
            $messageIds[] = $message->id;
        }

        // Append to context
        $this->summaryStore->appendContextMessages($conversation->id, $messageIds);

        $tokenCount = $this->summaryStore->getContextTokenCount($conversation->id);

        $this->assertGreaterThan(0, $tokenCount);
    }

    /**
     * Test compaction decision evaluation.
     */
    public function test_compaction_decision(): void
    {
        $conversation = $this->createConversation();

        // Create messages
        for ($i = 1; $i <= 10; $i++) {
            $message = $this->createMessage($conversation, str_repeat("Test message content {$i} ", 200));
            $this->summaryStore->appendContextMessage($conversation->id, $message->id);
        }

        // Evaluate compaction
        $decision = $this->compactionEngine->evaluate($conversation->id, 100000);

        $this->assertNotNull($decision);
        $this->assertIsBool($decision->shouldCompact);
    }

    /**
     * Test leaf compaction.
     */
    public function test_leaf_compaction(): void
    {
        $conversation = $this->createConversation();

        // Create messages with substantial content
        $messageIds = [];
        for ($i = 1; $i <= 10; $i++) {
            $message = $this->createMessage($conversation, str_repeat("Test message {$i} content for compaction test. ", 50));
            $messageIds[] = $message->id;
        }

        // Append to context
        $this->summaryStore->appendContextMessages($conversation->id, $messageIds);

        // Get initial token count
        $initialTokens = $this->summaryStore->getContextTokenCount($conversation->id);

        // Run compaction with a low threshold to trigger it
        $result = $this->compactionEngine->compact(
            $conversation->id,
            100, // Low threshold to ensure compaction triggers
            fn ($content, $aggressive, $options) => strlen($content) > 50 ? substr($content, 0, 50) . ' [Compacted]' : $content
        );

        $this->assertTrue($result->actionTaken, 'Compaction should have been triggered');
        $this->assertNotNull($result->createdSummaryId);
        $this->assertLessThan($initialTokens, $result->tokensAfter, 'Tokens after should be less than before');
    }

    /**
     * Test integrity check.
     */
    public function test_integrity_check(): void
    {
        $conversation = $this->createConversation();

        // Run integrity check
        $report = $this->integrityChecker->scan($conversation->id);

        $this->assertTrue($report->isHealthy());
        $this->assertEquals(8, $report->passCount);
    }

    /**
     * Test summary lineage.
     */
    public function test_summary_lineage(): void
    {
        $conversation = $this->createConversation();

        // Create messages
        $messageIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $message = $this->createMessage($conversation, "Test message {$i}");
            $messageIds[] = $message->id;
        }

        // Create a leaf summary
        $summary = $this->summaryStore->insertSummary([
            'summary_id' => 'sum_lineage_test',
            'conversation_id' => $conversation->id,
            'kind' => 'leaf',
            'depth' => 0,
            'content' => 'Test summary',
            'token_count' => 50,
        ]);

        // Link to messages
        $this->summaryStore->linkSummaryToMessages($summary->summaryId, $messageIds);

        // Verify lineage
        $linkedMessages = $this->summaryStore->getSummaryMessages($summary->summaryId);
        $this->assertCount(5, $linkedMessages);
        $this->assertEquals($messageIds, $linkedMessages);
    }

    /**
     * Test context item replacement with summary.
     */
    public function test_context_replacement(): void
    {
        $conversation = $this->createConversation();

        // Create messages
        $messageIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $message = $this->createMessage($conversation, "Test message {$i}");
            $messageIds[] = $message->id;
        }

        // Append to context
        $this->summaryStore->appendContextMessages($conversation->id, $messageIds);

        // Verify initial state
        $contextItems = $this->summaryStore->getContextItems($conversation->id);
        $this->assertCount(5, $contextItems);

        // Create a summary
        $summary = $this->summaryStore->insertSummary([
            'summary_id' => 'sum_replace_test',
            'conversation_id' => $conversation->id,
            'kind' => 'leaf',
            'depth' => 0,
            'content' => 'Replacement summary',
            'token_count' => 50,
        ]);

        // Replace range
        $this->summaryStore->replaceContextRangeWithSummary(
            $conversation->id,
            0,
            4,
            $summary->summaryId
        );

        // Verify replacement
        $contextItems = $this->summaryStore->getContextItems($conversation->id);
        $this->assertCount(1, $contextItems);
        $this->assertEquals('summary', $contextItems[0]->itemType);
        $this->assertEquals($summary->summaryId, $contextItems[0]->summaryId);
    }
}

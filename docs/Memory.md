# Lossless Memory System

## Overview

LaraClaw implements a **lossless memory compaction system** designed to manage conversation context within token budgets while preserving the ability to reconstruct original content. The system uses hierarchical summarization with a directed acyclic graph (DAG) structure, ensuring no information is permanently lost during compaction.

## Goals

1. **Token Budget Management**: Keep conversation context within configurable token limits
2. **Lossless Compaction**: Summaries maintain links to source messages, enabling full reconstruction
3. **Fresh Tail Protection**: Recent messages are preserved in their original form
4. **Hierarchical Summaries**: Multi-depth summarization allows efficient compression at scale
5. **Integrity Validation**: Built-in checks ensure data consistency and referential integrity

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          MemoryEngineService                                 │
│                         (Facade / Entry Point)                               │
└─────────────────────────────────────────────────────────────────────────────┘
                                       │
           ┌───────────────────────────┼───────────────────────────┐
           ▼                           ▼                           ▼
┌─────────────────────┐   ┌─────────────────────┐   ┌─────────────────────┐
│     SummaryStore    │   │   CompactionEngine  │   │   IntegrityChecker  │
│                     │   │                     │   │                     │
│ • Context items     │   │ • Leaf passes       │   │ • Contiguity check  │
│ • Summary records   │   │ • Condensed passes  │   │ • Reference check   │
│ • Message linking   │   │ • Fresh tail        │   │ • Lineage check     │
│ • Parent linking    │   │ • Escalation        │   │ • Token consistency │
└─────────────────────┘   └─────────────────────┘   └─────────────────────┘
           │                           │                           │
           └───────────────────────────┼───────────────────────────┘
                                       ▼
                     ┌─────────────────────────────────┐
                     │       Database Tables           │
                     │                                 │
                     │  • memory_context_items         │
                     │  • memory_summaries             │
                     │  • memory_summary_messages      │
                     │  • memory_summary_parents       │
                     └─────────────────────────────────┘
```

## Components

### 1. MemoryContextItem Model

Represents an ordered list of items forming the conversation context. Each item is either a raw message or a summary.

**Database Table:** `memory_context_items`

| Column | Type | Description |
|--------|------|-------------|
| `conversation_id` | int | FK to conversations.id |
| `ordinal` | int | Position in context (0-based, contiguous) |
| `item_type` | string | `'message'` or `'summary'` |
| `message_id` | int? | FK to conversation_messages.id (if message type) |
| `summary_id` | string? | FK to memory_summaries.summary_id (if summary type) |
| `created_at` | timestamp | Creation timestamp |

**Key Methods:**
- [`isMessage()`](../app/Models/MemoryContextItem.php:139): Check if item is a message
- [`isSummary()`](../app/Models/MemoryContextItem.php:147): Check if item is a summary
- [`getTokenCount()`](../app/Models/MemoryContextItem.php:155): Get token count for this item

### 2. MemorySummary Model

Stores hierarchical conversation summaries with full lineage tracking.

**Database Table:** `memory_summaries`

| Column | Type | Description |
|--------|------|-------------|
| `summary_id` | string | Primary key (e.g., `sum_abc123...`) |
| `conversation_id` | int | FK to conversations.id |
| `kind` | string | `'leaf'` or `'condensed'` |
| `depth` | int | Summary depth (0 = leaf, 1+ = condensed) |
| `content` | text | Summary text content |
| `token_count` | int | Approximate token count |
| `earliest_at` | timestamp? | Earliest timestamp of source content |
| `latest_at` | timestamp? | Latest timestamp of source content |
| `descendant_count` | int | Number of descendant summaries |
| `descendant_token_count` | int | Total tokens in descendants |
| `source_message_token_count` | int | Original message tokens before compaction |
| `file_ids` | array | File IDs referenced in this summary |

**Summary Kinds:**

| Kind | Depth | Description |
|------|-------|-------------|
| `leaf` | 0 | Summarizes raw conversation messages |
| `condensed` | 1+ | Summarizes lower-level summaries |

**Key Methods:**
- [`isLeaf()`](../app/Models/MemorySummary.php:171): Check if leaf summary
- [`isCondensed()`](../app/Models/MemorySummary.php:179): Check if condensed summary
- [`getCompressionRatio()`](../app/Models/MemorySummary.php:187): Get compression ratio

### 3. SummaryStore Service

Handles persistence and retrieval of summaries and context items.

**Key Operations:**
- [`appendContextMessage()`](../app/Services/Memory/SummaryStore.php:147): Add a message to context
- [`replaceContextRangeWithSummary()`](../app/Services/Memory/SummaryStore.php:209): Replace messages with summary
- [`linkSummaryToMessages()`](../app/Services/Memory/SummaryStore.php:290): Link leaf summary to source messages
- [`linkSummaryToParents()`](../app/Services/Memory/SummaryStore.php:310): Link condensed summary to parent summaries

### 4. CompactionEngine Service

Implements the lossless compaction algorithm with two-phase processing.

**Key Operations:**
- [`evaluate()`](../app/Services/Memory/CompactionEngine.php:72): Check if compaction is needed
- [`compact()`](../app/Services/Memory/CompactionEngine.php:109): Run full compaction sweep
- [`compactUntilUnder()`](../app/Services/Memory/CompactionEngine.php:271): Compact until under target tokens

### 5. IntegrityChecker Service

Validates the integrity of the memory system.

**Checks Performed:**
1. Conversation exists
2. Context items have contiguous ordinals
3. All context item references are valid
4. Summaries have proper lineage
5. No orphaned summaries
6. Token counts are consistent
7. Message sequence is contiguous
8. No duplicate context references

---

## Compaction Flow

### Trigger Conditions

Compaction is triggered when:

1. **Threshold Exceeded**: Context tokens exceed `context_threshold × token_budget`
2. **Leaf Trigger**: Raw message tokens outside fresh tail exceed `leaf_chunk_tokens`

### Two-Phase Compaction

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         Compaction Flow                                      │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  Phase 1: Leaf Pass (Messages → Leaf Summaries)                             │
│  ─────────────────────────────────────────────                              │
│                                                                              │
│    [Msg0] [Msg1] [Msg2] ... [MsgN]                                          │
│         │                   │                                                │
│         └─────► [Leaf Summary (depth=0)] ◄─────┘                            │
│                       │                                                      │
│                       ▼                                                      │
│              Links to source messages                                        │
│              stored in memory_summary_messages                               │
│                                                                              │
│                                                                              │
│  Phase 2: Condensed Pass (Leaf Summaries → Condensed Summaries)             │
│  ──────────────────────────────────────────────────────────────────         │
│                                                                              │
│    [Leaf0] [Leaf1] [Leaf2] [Leaf3]                                          │
│         │                   │                                                │
│         └─► [Condensed Summary (depth=1)] ◄──┘                              │
│                       │                                                      │
│                       ▼                                                      │
│              Links to parent summaries                                       │
│              stored in memory_summary_parents                                │
│                                                                              │
│                                                                              │
│  Fresh Tail Protection                                                       │
│  ─────────────────────                                                       │
│                                                                              │
│    [Summary] [Summary] [Msg] [Msg] [Msg] [Msg] [Msg] [Msg]                  │
│                               │                              │               │
│                               └──── Fresh Tail (N msgs) ────┘               │
│                                     Never compacted                          │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Three-Level Escalation

When summarization doesn't reduce tokens sufficiently, the engine escalates:

| Level | Description | Behavior |
|-------|-------------|----------|
| `normal` | Standard summarization | Full summary with context preservation |
| `aggressive` | Aggressive summarization | More concise summary, less context |
| `fallback` | Truncation | Simple truncation with marker |

### Feedback-Aware Compaction

Messages with positive feedback are preserved longer by applying a threshold reduction, making them less likely to be included in compaction chunks.

---

## Context Window Structure

The context window is an ordered list of items, oldest first:

```
Ordinal:  0        1        2        3        4        5        6
         ┌────────┬────────┬────────┬────────┬────────┬────────┬────────┐
         │Summary │Summary │ Msg    │ Msg    │ Msg    │ Msg    │ Msg    │
         │depth=1 │depth=0 │ id=42  │ id=43  │ id=44  │ id=45  │ id=46  │
         └────────┴────────┴────────┴────────┴────────┴────────┴────────┘
                  │                 │                          │
                  │                 └── Fresh tail starts ─────┘
                  │                     (protected from compaction)
                  │
                  └── Condensed summary (depth=1)
                      summarizes leaf summaries (depth=0)
```

---

## Lossless Reconstruction

The DAG structure enables full reconstruction of original content:

### For Leaf Summaries

```php
$summary = MemorySummary::find('sum_abc123');
$sourceMessageIds = DB::table('memory_summary_messages')
    ->where('summary_id', 'sum_abc123')
    ->orderBy('ordinal')
    ->pluck('message_id');

$originalMessages = ConversationMessage::whereIn('id', $sourceMessageIds)->get();
```

### For Condensed Summaries

```php
$summary = MemorySummary::find('sum_def456');
$parentSummaryIds = DB::table('memory_summary_parents')
    ->where('summary_id', 'sum_def456')
    ->orderBy('ordinal')
    ->pluck('parent_summary_id');

// Recursively traverse to leaf summaries, then to messages
```

---

## Configuration

All settings in `config/laraclaw.php`:

```php
'memory' => [
    // Enable/disable lossless memory
    'lossless_enabled' => env('LARACLAW_MEMORY_LOSSLESS_ENABLED', true),
    
    // Token budget for context window
    'lossless_token_budget' => env('LARACLAW_MEMORY_TOKEN_BUDGET', 100000),
    
    // Threshold ratio to trigger compaction (0.75 = 75% of budget)
    'lossless_context_threshold' => env('LARACLAW_MEMORY_CONTEXT_THRESHOLD', 0.75),
    
    // Number of recent messages to protect from compaction
    'lossless_fresh_tail' => env('LARACLAW_MEMORY_FRESH_TAIL', 8),
],
```

### Environment Variables

```env
# Enable lossless memory
LARACLAW_MEMORY_LOSSLESS_ENABLED=true

# Token budget
LARACLAW_MEMORY_TOKEN_BUDGET=100000

# Compaction threshold (0.0-1.0)
LARACLAW_MEMORY_CONTEXT_THRESHOLD=0.75

# Fresh tail protection count
LARACLAW_MEMORY_FRESH_TAIL=8
```

---

## Usage Examples

### Appending Messages to Context

```php
use App\Services\MemoryEngineService;

$memory = app(MemoryEngineService::class);

// Append a single message
$memory->appendMessageToContext($conversationId, $messageId);

// Append multiple messages
$memory->appendMessagesToContext($conversationId, [$messageId1, $messageId2]);
```

### Evaluating Compaction Need

```php
$decision = $memory->evaluateCompaction(
    conversationId: $conversationId,
    tokenBudget: 100000
);

if ($decision->shouldCompact) {
    echo "Compaction needed: {$decision->currentTokens} > {$decision->threshold}";
}
```

### Running Compaction

```php
use App\Services\Memory\LosslessCompactionService;

$service = app(LosslessCompactionService::class);

// Set custom summarizer (optional)
$service->setSummarizer(function (string $content, bool $aggressive, array $options) {
    // Use LLM to summarize
    return $llmClient->summarize($content, $aggressive);
});

// Compact a single conversation
$result = $service->compactConversation($conversationId);

echo "Tokens: {$result->tokensBefore} → {$result->tokensAfter}";
echo "Saved: {$result->getTokenReduction()} tokens";
```

### Running Integrity Checks

```php
$report = $memory->checkIntegrity($conversationId);

if ($report->isHealthy()) {
    echo "All {$report->passCount} checks passed";
} else {
    foreach ($report->getFailures() as $failure) {
        echo "FAIL: {$failure->name} - {$failure->message}";
    }
    
    $suggestions = $memory->getRepairPlan($report);
    foreach ($suggestions as $suggestion) {
        echo "Suggestion: {$suggestion}";
    }
}
```

### Getting Context for Agent Prompts

```php
$context = $memory->getLosslessContextForAgent(
    conversationId: $conversationId,
    maxTokens: 4000
);

// Returns formatted context string with summaries and messages
```

---

## Queue Job

Compaction can be run asynchronously via queue job:

```php
use App\Jobs\LosslessCompactionJob;

// Compact a single conversation
dispatch(new LosslessCompactionJob(conversationId: 123));

// Compact all conversations
dispatch(new LosslessCompactionJob(compactAll: true));

// Dry run (no changes)
dispatch(new LosslessCompactionJob(conversationId: 123, dryRun: true));
```

### Job Properties

| Property | Value |
|----------|-------|
| `$tries` | 3 |
| `$timeout` | 300 seconds |
| `retryUntil()` | 30 minutes |

---

## Database Schema

### memory_context_items

```sql
CREATE TABLE memory_context_items (
    conversation_id BIGINT NOT NULL,
    ordinal INT NOT NULL,
    item_type VARCHAR(10) NOT NULL,
    message_id BIGINT NULL,
    summary_id VARCHAR(32) NULL,
    created_at TIMESTAMP NULL,
    PRIMARY KEY (conversation_id, ordinal),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id),
    FOREIGN KEY (message_id) REFERENCES conversation_messages(id),
    FOREIGN KEY (summary_id) REFERENCES memory_summaries(summary_id)
);
```

### memory_summaries

```sql
CREATE TABLE memory_summaries (
    summary_id VARCHAR(32) PRIMARY KEY,
    conversation_id BIGINT NOT NULL,
    kind VARCHAR(10) NOT NULL,
    depth INT NOT NULL DEFAULT 0,
    content TEXT NOT NULL,
    token_count INT NOT NULL,
    earliest_at TIMESTAMP NULL,
    latest_at TIMESTAMP NULL,
    descendant_count INT NOT NULL DEFAULT 0,
    descendant_token_count INT NOT NULL DEFAULT 0,
    source_message_token_count INT NOT NULL DEFAULT 0,
    file_ids JSON NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id)
);
```

### memory_summary_messages

```sql
CREATE TABLE memory_summary_messages (
    summary_id VARCHAR(32) NOT NULL,
    message_id BIGINT NOT NULL,
    ordinal INT NOT NULL,
    PRIMARY KEY (summary_id, message_id),
    FOREIGN KEY (summary_id) REFERENCES memory_summaries(summary_id),
    FOREIGN KEY (message_id) REFERENCES conversation_messages(id)
);
```

### memory_summary_parents

```sql
CREATE TABLE memory_summary_parents (
    summary_id VARCHAR(32) NOT NULL,
    parent_summary_id VARCHAR(32) NOT NULL,
    ordinal INT NOT NULL,
    PRIMARY KEY (summary_id, parent_summary_id),
    FOREIGN KEY (summary_id) REFERENCES memory_summaries(summary_id),
    FOREIGN KEY (parent_summary_id) REFERENCES memory_summaries(summary_id)
);
```

---

## Best Practices

### 1. Configure Appropriate Token Budget

Set the token budget based on your LLM's context window:

```php
// For GPT-4 with 128k context
'lossless_token_budget' => 100000,

// Leave headroom for system prompt and response
```

### 2. Adjust Fresh Tail for Your Use Case

```php
// For conversational agents (preserve recent context)
'lossless_fresh_tail' => 12,

// For task-oriented agents (more aggressive compaction)
'lossless_fresh_tail' => 4,
```

### 3. Implement a Quality Summarizer

The default summarizer uses simple truncation. For production, implement an LLM-based summarizer:

```php
$service->setSummarizer(function (string $content, bool $aggressive, array $options) {
    $prompt = $aggressive
        ? "Summarize concisely (2-3 sentences): {$content}"
        : "Summarize with key details preserved: {$content}";
    
    return $llmClient->complete($prompt);
});
```

### 4. Run Integrity Checks Periodically

```php
// In a scheduled command
$schedule->call(function () {
    $service = app(LosslessCompactionService::class);
    $result = $service->checkIntegrityAll();
    
    if ($result['unhealthy'] > 0) {
        // Alert administrators
    }
})->weekly();
```

### 5. Monitor Compaction Results

```php
$result = $service->compactConversation($conversationId);

if ($result->actionTaken) {
    Log::info('Compaction complete', [
        'conversation_id' => $conversationId,
        'tokens_saved' => $result->getTokenReduction(),
        'compression_ratio' => $result->getCompressionRatio(),
        'level' => $result->level,
    ]);
}
```

---

## Troubleshooting

### Compaction Not Reducing Tokens

1. Check if summarizer is returning content longer than input
2. Verify escalation is working (check `level` in result)
3. Ensure fresh tail isn't too large

### Integrity Check Failures

1. **context_items_contiguous**: Run `SummaryStore::resequenceOrdinals()`
2. **context_items_valid_refs**: Remove dangling references
3. **summaries_have_lineage**: Add missing links to `memory_summary_messages` or `memory_summary_parents`
4. **no_orphan_summaries**: Remove orphaned summaries from `memory_summaries`

### High Memory Usage

1. Reduce `lossless_token_budget`
2. Reduce `lossless_fresh_tail`
3. Run compaction more frequently
4. Implement more aggressive summarization

---

## Related Files

- [`app/Models/MemoryContextItem.php`](../app/Models/MemoryContextItem.php) - Context item model
- [`app/Models/MemorySummary.php`](../app/Models/MemorySummary.php) - Summary model
- [`app/Services/MemoryEngineService.php`](../app/Services/MemoryEngineService.php) - Main service facade
- [`app/Services/Memory/SummaryStore.php`](../app/Services/Memory/SummaryStore.php) - Persistence layer
- [`app/Services/Memory/CompactionEngine.php`](../app/Services/Memory/CompactionEngine.php) - Compaction logic
- [`app/Services/Memory/IntegrityChecker.php`](../app/Services/Memory/IntegrityChecker.php) - Integrity validation
- [`app/Services/Memory/LosslessCompactionService.php`](../app/Services/Memory/LosslessCompactionService.php) - High-level API
- [`app/Jobs/LosslessCompactionJob.php`](../app/Jobs/LosslessCompactionJob.php) - Queue job
- [`app/DTOs/CompactionDecisionDTO.php`](../app/DTOs/CompactionDecisionDTO.php) - Decision DTO
- [`app/DTOs/CompactionResultDTO.php`](../app/DTOs/CompactionResultDTO.php) - Result DTO
- [`app/DTOs/IntegrityReportDTO.php`](../app/DTOs/IntegrityReportDTO.php) - Integrity report DTO

# Memory System Documentation

## Overview

LaraClaw implements a sophisticated **3-layer adaptive memory system** inspired by cognitive science and the Ebbinghaus forgetting curve. This system enables agents to remember user preferences, past interactions, and important context across conversations.

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
│     Layer 1         │   │     Layer 2         │   │     Layer 3         │
│  Episodic Memory    │   │   Semantic Index    │   │  Temporal Decay     │
│                     │   │                     │   │                     │
│ • Timestamped       │   │ • Full-text Search  │   │ • Ebbinghaus        │
│   events            │──▶│ • BM25 Ranking      │──▶│   Forgetting Curve  │
│ • Outcomes          │   │ • Scout Integration │   │ • Access Frequency  │
│ • Importance        │   │                     │   │   Strengthening     │
└─────────────────────┘   └─────────────────────┘   └─────────────────────┘
          │                           │                           │
          └───────────────────────────┼───────────────────────────┘
                                      ▼
                    ┌─────────────────────────────────┐
                    │      MemoryConsolidator         │
                    │   (Scheduled Maintenance)       │
                    │                                 │
                    │  • Decay importance             │
                    │  • Prune low-value memories     │
                    │  • Merge duplicates             │
                    └─────────────────────────────────┘
```

## Components

### 1. EpisodicMemory Model

The primary storage for timestamped events with outcomes and importance scoring.

**Database Table:** `memories`

| Column | Type | Description |
|--------|------|-------------|
| `id` | UUID | Unique identifier |
| `sender_id` | string | User identifier (channel-specific) |
| `channel` | enum | Communication channel (discord, telegram, etc.) |
| `agent_id` | string | Optional agent context |
| `event_type` | enum | Type of memory event |
| `content` | text | The memory content |
| `outcome` | text | Optional outcome/result |
| `importance` | decimal(3,2) | Importance score (0.0-1.0) |
| `access_count` | int | Number of times accessed |
| `last_accessed_at` | timestamp | Last access timestamp |
| `created_at` | timestamp | Creation timestamp |

### 2. Event Types

Defined in [`EpisodicEventTypeEnum`](../app/Enums/EpisodicEventTypeEnum.php):

| Event Type | Default Importance | Description |
|------------|-------------------|-------------|
| `CORRECTION` | 0.90 | User corrected agent behavior |
| `PREFERENCE_LEARNED` | 0.80 | Learned user preference |
| `FACT_STORED` | 0.60 | General fact about user |
| `TASK_COMPLETED` | 0.50 | Successfully completed task |
| `DELEGATION_RESULT` | 0.50 | Result of agent delegation |

### 3. MemoryRelevanceScorer

Implements hybrid scoring combining three signals:

```
relevance = (fts_score × fts_weight) + (temporal_score × temporal_weight) + (importance × importance_weight)
```

**Default Weights:**
- FTS Weight: 0.4
- Temporal Weight: 0.3
- Importance Weight: 0.3

### 4. MemoryConsolidator

Handles maintenance operations:
- **Decay**: Reduce importance of unaccessed memories
- **Prune**: Delete low-value, unaccessed memories
- **Merge**: Combine duplicate memories

---

## Memory Lifecycle

### Stage 1: Creation

When a memory is created, it receives:

1. **UUID** - Unique identifier
2. **Event Type** - Determines default importance
3. **Initial Importance** - Based on event type or custom value
4. **Access Count** - Set to 0
5. **Last Accessed** - Set to current time

```php
// Example: Recording a preference learned
$memoryId = $memoryEngine->recordEvent(
    senderId: 'user_12345',
    channel: ChannelEnum::DISCORD,
    event: [
        'type' => EpisodicEventTypeEnum::PREFERENCE_LEARNED,
        'content' => 'User prefers dark mode in all applications',
        'outcome' => 'Preference stored for future UI decisions',
        'importance' => 0.80, // Default for PREFERENCE_LEARNED
    ]
);
```

### Stage 2: Active Use (Reinforcement)

When a memory is retrieved and used:

1. **Access Count** increments by 1
2. **Last Accessed** updates to current time
3. **Temporal Score** improves due to access bonus

```php
// Reinforcement happens automatically during search
$results = $memoryEngine->search(
    senderId: 'user_12345',
    channel: ChannelEnum::DISCORD,
    query: 'UI preferences'
);

// Or manually reinforce a specific memory
$memoryEngine->reinforce($memoryId);
```

**Temporal Score Formula:**
```
temporal_score = e^(-rate × days_since_access) × (1 + bonus × access_count)
```

With default values:
- `rate = 0.05` (decay rate)
- `bonus = 0.02` (access bonus per access)

### Stage 3: Decay (Without Use)

After `decay_after_days` (default: 7 days) without access:

1. **Importance** multiplies by `decay_factor` (default: 0.95)
2. This is a **5% reduction** per consolidation run
3. Decay continues until `min_importance` (default: 0.05) is reached

```
Day 0:   importance = 0.80
Day 7:   importance = 0.80 × 0.95 = 0.76
Day 14:  importance = 0.76 × 0.95 = 0.72
Day 21:  importance = 0.72 × 0.95 = 0.68
...
Day 70:  importance ≈ 0.49 (after 10 decay cycles)
```

### Stage 4: Pruning (Deletion)

Memories are deleted when ALL conditions are met:

1. **Importance** < `prune_max_importance` (default: 0.1)
2. **Access Count** = 0 (never accessed)
3. **Age** > `prune_after_days` (default: 30 days)

```
┌─────────────────────────────────────────────────────────────────┐
│                     Memory Lifecycle Graph                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Creation                                                       │
│     │                                                           │
│     ▼                                                           │
│  ┌─────────────────┐                                            │
│  │  importance=0.8 │                                            │
│  │  access_count=0 │                                            │
│  └────────┬────────┘                                            │
│           │                                                     │
│           ▼                                                     │
│     ┌─────────┐                                                 │
│     │  USED?  │──── Yes ───▶  ┌─────────────────┐               │
│     └────┬────┘               │ access_count++  │               │
│          │                    │ last_access=now │               │
│          No                   │ importance +=   │               │
│          │                    │   (bonus)       │               │
│          ▼                    └────────┬────────┘               │
│   After 7 days                         │                        │
│          │                             │                        │
│          ▼                             │                        │
│  ┌─────────────────┐                   │                        │
│  │ importance ×    │                   │                        │
│  │   0.95 (decay)  │                   │                        │
│  └────────┬────────┘                   │                        │
│           │                            │                        │
│           ▼                            │                        │
│     ┌────────────┐                     │                        │
│     │ importance │─── < 0.1 ───┐       │                        │
│     │            │            │       │                        │
│     │ access=0?  │─── Yes ────┼───▶ PRUNED                      │
│     │            │            │       │                        │
│     │ age > 30d? │─── Yes ────┘       │                        │
│     └────────────┘                     │                        │
│                                        │                        │
│          ◀─────────────────────────────┘                        │
│                    (Cycle continues)                             │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Stage 5: Merging (Deduplication)

When two memories are highly similar:

1. **Similarity** > `merge_similarity_threshold` (default: 0.8)
2. **Same Event Type**
3. Newer memory absorbs older memory:
   - Importance increases: `newer.importance += older.importance × 0.2`
   - Access counts combine: `newer.access_count += older.access_count`
   - Older memory is deleted

---

## Scheduled Maintenance

### Required Scheduler Entry

Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    // Run memory consolidation daily at 3 AM
    $schedule->command('laraclaw:memory:consolidate')
        ->dailyAt('03:00')
        ->withoutOverlapping()
        ->onOneServer();
}
```

### Manual Execution

```bash
# Consolidate all users
php artisan laraclaw:memory:consolidate

# Consolidate specific user
php artisan laraclaw:memory:consolidate --sender=user_12345 --channel=discord

# Dry run (preview changes)
php artisan laraclaw:memory:consolidate --dry-run
```

### Command Output

```
Consolidating memories for all users...
Found 42 unique users with memories.

Consolidation complete:
  - Total decayed: 156 memories
  - Total pruned: 23 memories
  - Total merged: 8 duplicates
```

---

## Configuration

All settings in [`config/memory.php`](../config/memory.php):

### Scoring Weights

```php
'scoring' => [
    'fts_weight' => 0.4,        // Full-text search weight
    'temporal_weight' => 0.3,   // Temporal decay weight
    'importance_weight' => 0.3, // Importance weight
],
```

### Decay Parameters

```php
'decay' => [
    'rate' => 0.05,           // Decay rate (higher = faster decay)
    'access_bonus' => 0.02,   // Bonus per access
    'min_importance' => 0.05, // Minimum before pruning eligible
],
```

### Consolidation Parameters

```php
'consolidation' => [
    'decay_after_days' => 7,        // Days before decay starts
    'decay_factor' => 0.95,         // 5% reduction per cycle
    'prune_after_days' => 30,       // Days before pruning eligible
    'prune_max_importance' => 0.1,  // Max importance for pruning
    'merge_similarity_threshold' => 0.8, // Jaccard similarity
    'merge_check_limit' => 200,     // Max memories to check
],
```

### Environment Variables

```env
# Scoring
MEMORY_FTS_WEIGHT=0.4
MEMORY_TEMPORAL_WEIGHT=0.3
MEMORY_IMPORTANCE_WEIGHT=0.3

# Decay
MEMORY_DECAY_RATE=0.05
MEMORY_ACCESS_BONUS=0.02
MEMORY_MIN_IMPORTANCE=0.05

# Consolidation
MEMORY_DECAY_AFTER_DAYS=7
MEMORY_DECAY_FACTOR=0.95
MEMORY_PRUNE_AFTER_DAYS=30
MEMORY_PRUNE_MAX_IMPORTANCE=0.1
MEMORY_MERGE_THRESHOLD=0.8
MEMORY_MERGE_LIMIT=200
```

---

## Usage Examples

### Recording Events

```php
use App\Services\MemoryEngineService;
use App\Enums\ChannelEnum;
use App\Enums\EpisodicEventTypeEnum;

$memory = app(MemoryEngineService::class);

// Record a user correction (high importance)
$memory->recordEvent(
    senderId: 'user_12345',
    channel: ChannelEnum::DISCORD,
    event: [
        'type' => EpisodicEventTypeEnum::CORRECTION,
        'content' => 'User corrected: They prefer Python over JavaScript',
        'outcome' => 'Updated language preference',
    ]
);

// Record a completed task (medium importance)
$memory->recordEvent(
    senderId: 'user_12345',
    channel: ChannelEnum::DISCORD,
    event: [
        'type' => EpisodicEventTypeEnum::TASK_COMPLETED,
        'content' => 'Successfully deployed to production',
        'outcome' => 'Deployment completed without errors',
        'agent_id' => 'deploy-bot',
    ]
);
```

### Searching Memories

```php
// Search for relevant memories
$results = $memory->search(
    senderId: 'user_12345',
    channel: ChannelEnum::DISCORD,
    query: 'programming language preferences',
    limit: 10
);

// Results include relevance scores
foreach ($results as $result) {
    echo "[{$result['source']}] {$result['content']} (score: {$result['relevance_score']})\n";
}
```

### Getting Context for Agents

```php
// Get formatted context for prompt injection
$context = $memory->getContextForAgent(
    senderId: 'user_12345',
    channel: ChannelEnum::DISCORD,
    query: 'current project status'
);

// Returns formatted sections:
// ## Relevant Memories
// 📝 User prefers Python over JavaScript
// 📝 Deployment completed without errors
//
// ## Important Context
// ⚠️ Correction: They prefer Python over JavaScript
// ⭐ Preference Learned: Dark mode preference
```

---

## Legacy KeyValueMemory

> **Note:** KeyValueMemory is a legacy system maintained for backward compatibility. It provides simple key-value storage but lacks the sophisticated features of EpisodicMemory.

### Differences

| Feature | KeyValueMemory | EpisodicMemory |
|---------|---------------|----------------|
| Search | Basic token matching | Full-text + BM25 |
| Decay | None | Ebbinghaus curve |
| Importance | None | Per-event scoring |
| Outcomes | None | Supported |
| Deduplication | None | Automatic merging |

### Migration Path

To migrate from KeyValueMemory to EpisodicMemory:

```php
// Old: KeyValueMemory
KeyValueMemory::store($senderId, $channel, 'timezone', 'Europe/Berlin');

// New: EpisodicMemory
$memory->recordEvent(
    senderId: $senderId,
    channel: $channel,
    event: [
        'type' => EpisodicEventTypeEnum::FACT_STORED,
        'content' => 'User timezone: Europe/Berlin',
        'importance' => 0.60,
    ]
);
```

---

## Best Practices

### 1. Choose Appropriate Event Types

- Use `CORRECTION` when user explicitly corrects agent behavior
- Use `PREFERENCE_LEARNED` for user preferences discovered through interaction
- Use `FACT_STORED` for general facts about the user
- Use `TASK_COMPLETED` for successful task outcomes
- Use `DELEGATION_RESULT` for inter-agent delegation results

### 2. Set Meaningful Content

```php
// Bad: Vague content
'content' => 'User likes something'

// Good: Specific, searchable content
'content' => 'User prefers VS Code with Vim keybindings for TypeScript development'
```

### 3. Include Outcomes When Relevant

```php
'content' => 'User requested deployment to staging',
'outcome' => 'Deployed successfully, URL: https://staging.example.com'
```

### 4. Schedule Regular Consolidation

Run consolidation at least daily to:
- Prevent memory bloat
- Maintain relevant importance scores
- Remove stale, unused memories

### 5. Monitor Memory Statistics

```bash
# Check memory health
php artisan laraclaw:memory:consolidate --sender=user_12345 --channel=discord --dry-run

# Output shows:
# - Total memories
# - Average importance
# - Prune candidates
# - Old (unaccessed) memories
```

---

## Troubleshooting

### Memories Not Being Retrieved

1. Check importance threshold: `shouldBeSearchable()` requires importance ≥ 0.1
2. Verify Scout configuration for full-text search
3. Check that sender_id and channel match exactly

### Too Many Memories Being Pruned

1. Increase `prune_max_importance` threshold
2. Increase `prune_after_days` window
3. Ensure memories are being reinforced when used

### Memories Not Decaying

1. Verify consolidation is scheduled and running
2. Check `decay_after_days` setting
3. Ensure `last_accessed_at` is being updated

---

## Related Files

- [`app/Models/Memory.php`](../app/Models/Memory.php) - Memory model
- [`app/Services/MemoryEngineService.php`](../app/Services/MemoryEngineService.php) - Main service
- [`app/Services/Memory/MemoryConsolidator.php`](../app/Services/Memory/MemoryConsolidator.php) - Maintenance logic
- [`app/Services/Memory/MemoryRelevanceScorer.php`](../app/Services/Memory/MemoryRelevanceScorer.php) - Scoring logic
- [`app/Console/Commands/LaraClawMemoryConsolidateCommand.php`](../app/Console/Commands/LaraClawMemoryConsolidateCommand.php) - CLI command
- [`config/memory.php`](../config/memory.php) - Configuration


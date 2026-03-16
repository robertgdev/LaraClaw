<?php

namespace App\Jobs;

use App\DTOs\CompactionResultDTO;
use App\Models\Conversation;
use App\Services\Memory\LosslessCompactionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to run lossless memory compaction in the background.
 *
 * This job can be dispatched to compact a single conversation
 * or all conversations with context items.
 *
 * Usage:
 *   // Compact a single conversation
 *   dispatch(new LosslessCompactionJob(conversationId: 123));
 *
 *   // Compact all conversations
 *   dispatch(new LosslessCompactionJob(compactAll: true));
 *
 *   // With custom summarizer (via service binding)
 *   app(LosslessCompactionService::class)->setSummarizer($mySummarizer);
 *   dispatch(new LosslessCompactionJob(conversationId: 123));
 */
class LosslessCompactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 300;

    /**
     * Create a new job instance.
     *
     * @param  int|null  $conversationId  Specific conversation to compact (null for all)
     * @param  bool  $compactAll  Whether to compact all conversations
     * @param  int|null  $tokenBudget  Override default token budget
     * @param  bool  $dryRun  Run in dry-run mode (no changes)
     */
    public function __construct(
        public readonly ?int $conversationId = null,
        public readonly bool $compactAll = false,
        public readonly ?int $tokenBudget = null,
        public readonly bool $dryRun = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(LosslessCompactionService $service): void
    {
        // Check if lossless memory is enabled
        if (! $service->isEnabled()) {
            Log::warning('LosslessCompactionJob: Lossless memory is not enabled');

            return;
        }

        // Validate conversation exists if specified
        if ($this->conversationId !== null && ! $this->compactAll) {
            $conversation = Conversation::find($this->conversationId);
            if (! $conversation) {
                Log::error('LosslessCompactionJob: Conversation not found', [
                    'conversation_id' => $this->conversationId,
                ]);

                return;
            }

            $this->compactConversation($service, $this->conversationId);

            return;
        }

        // Compact all conversations
        if ($this->compactAll) {
            $this->compactAllConversations($service);

            return;
        }

        Log::warning('LosslessCompactionJob: No conversation specified and compactAll is false');
    }

    /**
     * Compact a single conversation.
     */
    protected function compactConversation(LosslessCompactionService $service, int $conversationId): void
    {
        Log::info('LosslessCompactionJob: Starting compaction', [
            'conversation_id' => $conversationId,
            'dry_run' => $this->dryRun,
        ]);

        try {
            $result = $service->compactConversation(
                $conversationId,
                $this->tokenBudget,
                $this->dryRun
            );

            $this->logResult($result, $conversationId);
        } catch (\Exception $e) {
            Log::error('LosslessCompactionJob: Compaction failed', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Compact all conversations with context items.
     */
    protected function compactAllConversations(LosslessCompactionService $service): void
    {
        Log::info('LosslessCompactionJob: Starting batch compaction', [
            'dry_run' => $this->dryRun,
        ]);

        try {
            $result = $service->compactAll($this->dryRun);

            Log::info('LosslessCompactionJob: Batch compaction complete', [
                'compacted' => $result->compacted,
                'skipped' => $result->skipped,
                'errors' => $result->errors,
            ]);

            if ($result->hasErrors()) {
                foreach ($result->errorDetails as $conversationId => $error) {
                    Log::error('LosslessCompactionJob: Error in conversation', [
                        'conversation_id' => $conversationId,
                        'error' => $error,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('LosslessCompactionJob: Batch compaction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Log the result of a single compaction.
     */
    protected function logResult(CompactionResultDTO $result, int $conversationId): void
    {
        if ($result->actionTaken) {
            Log::info('LosslessCompactionJob: Compaction complete', [
                'conversation_id' => $conversationId,
                'tokens_before' => $result->tokensBefore,
                'tokens_after' => $result->tokensAfter,
                'tokens_saved' => $result->tokensBefore - $result->tokensAfter,
                'level' => $result->level,
                'condensed' => $result->condensed,
            ]);
        } else {
            Log::info('LosslessCompactionJob: No compaction needed', [
                'conversation_id' => $conversationId,
                'tokens' => $result->tokensBefore,
            ]);
        }
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        $tags = ['lossless-compaction'];

        if ($this->conversationId) {
            $tags[] = "conversation:{$this->conversationId}";
        }

        if ($this->compactAll) {
            $tags[] = 'compact-all';
        }

        return $tags;
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(30);
    }
}

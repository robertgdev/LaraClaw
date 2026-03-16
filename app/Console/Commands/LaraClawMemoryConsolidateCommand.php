<?php

namespace App\Console\Commands;

use App\DTOs\IntegrityReportDTO;
use App\Enums\ChannelEnum;
use App\Models\Conversation;
use App\Models\Memory;
use App\Services\Memory\LosslessCompactionService;
use App\Services\MemoryEngineService;
use Illuminate\Console\Command;

class LaraClawMemoryConsolidateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laraclaw:memory:consolidate
                            {--sender= : Specific sender ID to consolidate}
                            {--channel= : Channel for the sender (discord, telegram, whatsapp, cli)}
                            {--conversation= : Specific conversation ID for lossless compaction}
                            {--lossless : Run lossless memory compaction instead of episodic}
                            {--integrity : Run integrity check on lossless memory}
                            {--dry-run : Show what would be done without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consolidate memories (decay, prune, merge) or run lossless compaction';

    /**
     * Execute the console command.
     */
    public function handle(
        MemoryEngineService $memory,
        LosslessCompactionService $losslessService
    ): int {
        $senderId = $this->option('sender');
        $channel = $this->option('channel');
        $conversationId = $this->option('conversation');
        $lossless = $this->option('lossless');
        $integrity = $this->option('integrity');
        $dryRun = $this->option('dry-run');

        // Handle lossless memory operations
        if ($lossless || $integrity) {
            return $this->handleLossless($losslessService, $conversationId, $integrity, $dryRun);
        }

        // Handle episodic memory consolidation
        if ($senderId && $channel) {
            return $this->consolidateForSender($memory, $senderId, $channel, $dryRun);
        }

        if (($senderId && ! $channel) || (! $senderId && $channel)) {
            $this->error('Both --sender and --channel must be specified together.');

            return 1;
        }

        return $this->consolidateAll($memory, $dryRun);
    }

    /**
     * Handle lossless memory operations using the service.
     */
    private function handleLossless(
        LosslessCompactionService $service,
        ?string $conversationId,
        bool $integrity,
        bool $dryRun
    ): int {
        if (! $service->isEnabled()) {
            $this->error('Lossless memory is not enabled. Set LARACLAW_MEMORY_LOSSLESS=true to enable.');

            return 1;
        }

        if ($integrity) {
            return $this->runIntegrityCheck($service, $conversationId);
        }

        return $this->runLosslessCompaction($service, $conversationId, $dryRun);
    }

    /**
     * Run integrity check using the service.
     */
    private function runIntegrityCheck(
        LosslessCompactionService $service,
        ?string $conversationId
    ): int {
        if ($conversationId) {
            $conversation = Conversation::find($conversationId);
            if (! $conversation) {
                $this->error("Conversation not found: {$conversationId}");

                return 1;
            }

            return $this->displayIntegrityReport(
                $service->checkIntegrity($conversation->id),
                $conversation->id,
                $service
            );
        }

        // Check all conversations
        $result = $service->checkIntegrityAll();

        if ($result['healthy'] + $result['unhealthy'] === 0) {
            $this->info('No conversations with lossless context found.');

            return 0;
        }

        $this->info("Checking integrity for {$result['healthy']} + {$result['unhealthy']} conversations...");

        $failures = 0;
        foreach ($result['reports'] as $convId => $report) {
            if (! $report->isHealthy()) {
                $this->displayIntegrityReport($report, $convId, $service, verbose: false);
                $failures++;
            }
        }

        if ($failures > 0) {
            $this->error("{$failures} conversation(s) failed integrity check.");

            return 1;
        }

        $this->info('All conversations passed integrity check.');

        return 0;
    }

    /**
     * Display an integrity report.
     */
    private function displayIntegrityReport(
        IntegrityReportDTO $report,
        int $conversationId,
        LosslessCompactionService $service,
        bool $verbose = true
    ): int {
        if ($verbose) {
            $this->info("Integrity report for conversation {$conversationId}:");
            $this->line("  - Checks passed: {$report->passCount}");
            $this->line("  - Checks failed: {$report->failCount}");
            $this->line("  - Warnings: {$report->warnCount}");

            if ($report->hasFailures()) {
                $this->newLine();
                $this->error('Failures:');
                foreach ($report->getFailures() as $failure) {
                    $this->line("  - {$failure->name}: {$failure->message}");
                }
            }

            if ($report->hasWarnings()) {
                $this->newLine();
                $this->warn('Warnings:');
                foreach ($report->getWarnings() as $warning) {
                    $this->line("  - {$warning->name}: {$warning->message}");
                }
            }

            if (! $report->isHealthy()) {
                $this->newLine();
                $this->info('Repair suggestions:');
                foreach ($service->getRepairPlan($report) as $suggestion) {
                    $this->line("  - {$suggestion}");
                }
            }
        }

        return $report->isHealthy() ? 0 : 1;
    }

    /**
     * Run lossless compaction using the service.
     */
    private function runLosslessCompaction(
        LosslessCompactionService $service,
        ?string $conversationId,
        bool $dryRun
    ): int {
        if ($conversationId) {
            $conversation = Conversation::find($conversationId);
            if (! $conversation) {
                $this->error("Conversation not found: {$conversationId}");

                return 1;
            }

            return $this->compactAndDisplay($service, $conversation->id, $dryRun);
        }

        // Compact all conversations
        $this->info('Compacting all conversations with lossless context...');

        if ($dryRun) {
            $this->warn('Dry run mode - no changes will be made');
        }

        $result = $service->compactAll($dryRun);

        $this->newLine();
        $this->info('Lossless compaction complete:');
        $this->line("  - Compacted: {$result->compacted} conversations");
        $this->line("  - Skipped: {$result->skipped} conversations");
        $this->line("  - Errors: {$result->errors} conversations");

        if ($result->hasErrors()) {
            $this->newLine();
            $this->error('Errors:');
            foreach ($result->errorDetails as $convId => $error) {
                $this->line("  - Conversation {$convId}: {$error}");
            }
        }

        return $result->hasErrors() ? 1 : 0;
    }

    /**
     * Compact a single conversation and display results.
     */
    private function compactAndDisplay(
        LosslessCompactionService $service,
        int $conversationId,
        bool $dryRun
    ): int {
        $evaluation = $service->evaluateCompaction($conversationId);

        $this->info("Conversation {$conversationId}:");
        $this->line("  - Current tokens: {$evaluation['current_tokens']}");
        $this->line("  - Token budget: {$evaluation['token_budget']}");
        $this->line("  - Threshold: {$evaluation['threshold']}");
        $this->line("  - Needs compaction: ".($evaluation['should_compact'] ? 'Yes' : 'No'));

        if (! $evaluation['should_compact']) {
            $this->info('  No compaction needed.');

            return 0;
        }

        if ($dryRun) {
            $this->warn('  Dry run - would run compaction');

            return 0;
        }

        $result = $service->compactConversation($conversationId, dryRun: false);

        if ($result->actionTaken) {
            $this->info('  Compaction complete:');
            $this->line("    - Tokens before: {$result->tokensBefore}");
            $this->line("    - Tokens after: {$result->tokensAfter}");
            $this->line("    - Saved: ".($result->tokensBefore - $result->tokensAfter).' tokens');
            $this->line("    - Level: {$result->level}");
            $this->line("    - Condensed: ".($result->condensed ? 'Yes' : 'No'));
        } else {
            $this->info('  No action taken.');
        }

        return 0;
    }

    /**
     * Consolidate memories for a specific sender.
     */
    private function consolidateForSender(
        MemoryEngineService $memory,
        string $senderId,
        string $channel,
        bool $dryRun
    ): int {
        try {
            $channelEnum = ChannelEnum::from($channel);
        } catch (\ValueError $e) {
            $this->error("Invalid channel: {$channel}. Valid options: discord, telegram, whatsapp, cli");

            return 1;
        }

        $this->info("Consolidating memories for sender: {$senderId} on channel: {$channel}");

        if ($dryRun) {
            $this->warn('Dry run mode - no changes will be made');
            $this->showStats($senderId, $channelEnum);

            return 0;
        }

        $result = $memory->consolidate($senderId, $channelEnum);

        $this->info('Consolidation complete:');
        $this->line("  - Decayed: {$result->decayed} memories");
        $this->line("  - Pruned: {$result->pruned} memories");
        $this->line("  - Merged: {$result->merged} duplicates");

        return 0;
    }

    /**
     * Consolidate memories for all users.
     */
    private function consolidateAll(MemoryEngineService $memoryEngineService, bool $dryRun): int
    {
        $this->info('Consolidating memories for all users...');

        // Get unique sender_id + channel combinations
        $memories = Memory::query()
            ->select('sender_id', 'channel')
            ->distinct()
            ->get();

        if ($memories->isEmpty()) {
            $this->info('No memories found to consolidate.');

            return 0;
        }

        $this->info("Found {$memories->count()} unique users with memories.");

        if ($dryRun) {
            $this->warn('Dry run mode - no changes will be made');

            foreach ($memories as $user) {
                $this->line("  - Sender: {$user->sender_id}, Channel: {$user->channel->value}");
            }

            return 0;
        }

        $totals = [
            'decayed' => 0,
            'pruned' => 0,
            'merged' => 0,
        ];

        $bar = $this->output->createProgressBar($memories->count());
        $bar->start();

        foreach ($memories as $memory) {
            try {
                $result = $memoryEngineService->consolidate($memory->sender_id, $memory->channel);
                $totals['decayed'] += $result->decayed;
                $totals['pruned'] += $result->pruned;
                $totals['merged'] += $result->merged;
            } catch (\Exception $e) {
                $this->error("Error consolidating for {$memory->sender_id}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Consolidation complete:');
        $this->line("  - Total decayed: {$totals['decayed']} memories");
        $this->line("  - Total pruned: {$totals['pruned']} memories");
        $this->line("  - Total merged: {$totals['merged']} duplicates");

        return 0;
    }

    /**
     * Show memory statistics for a sender.
     */
    private function showStats(string $senderId, ChannelEnum $channel): void
    {
        $stats = Memory::statsForSender($senderId, $channel);

        $this->newLine();
        $this->line('Current memory statistics:');
        $this->line("  - Total memories: {$stats->total}");
        $this->line('  - Average importance: '.number_format($stats->avgImportance, 2));
        $this->line("  - Not accessed in 7+ days: {$stats->oldCount}");
        $this->line("  - Prune candidates (low importance, unaccessed): {$stats->pruneCandidates}");
    }
}

<?php

namespace App\Console\Commands;

use App\DTOs\IntegrityReportDTO;
use App\Models\Conversation;
use App\Services\Memory\LosslessCompactionService;
use Illuminate\Console\Command;

class LaraClawMemoryConsolidateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laraclaw:memory:consolidate
                            {--conversation= : Specific conversation ID for lossless compaction}
                            {--integrity : Run integrity check on lossless memory}
                            {--dry-run : Show what would be done without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run lossless memory compaction and integrity checks';

    /**
     * Execute the console command.
     */
    public function handle(
        LosslessCompactionService $losslessService
    ): int {
        $conversationId = $this->option('conversation');
        $integrity = $this->option('integrity');
        $dryRun = $this->option('dry-run');

        if (! $losslessService->isEnabled()) {
            $this->error('Lossless memory is not enabled. Set LARACLAW_MEMORY_LOSSLESS=true to enable.');

            return 1;
        }

        if ($integrity) {
            return $this->runIntegrityCheck($losslessService, $conversationId);
        }

        return $this->runLosslessCompaction($losslessService, $conversationId, $dryRun);
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
}

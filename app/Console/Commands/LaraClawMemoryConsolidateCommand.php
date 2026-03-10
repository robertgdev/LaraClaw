<?php

namespace App\Console\Commands;

use App\Enums\ChannelEnum;
use App\Models\Memory;
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
                            {--dry-run : Show what would be done without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consolidate memories (decay, prune, merge) for all or specific users';

    /**
     * Execute the console command.
     */
    public function handle(MemoryEngineService $memory): int
    {
        $senderId = $this->option('sender');
        $channel = $this->option('channel');
        $dryRun = $this->option('dry-run');

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
        $this->line("  - Decayed: {$result['decayed']} memories");
        $this->line("  - Pruned: {$result['pruned']} memories");
        $this->line("  - Merged: {$result['merged']} duplicates");

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
                $totals['decayed'] += $result['decayed'];
                $totals['pruned'] += $result['pruned'];
                $totals['merged'] += $result['merged'];
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

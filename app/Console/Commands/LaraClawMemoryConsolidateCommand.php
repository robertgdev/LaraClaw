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
    private function consolidateAll(MemoryEngineService $memory, bool $dryRun): int
    {
        $this->info('Consolidating memories for all users...');

        // Get unique sender_id + channel combinations
        $users = Memory::query()
            ->select('sender_id', 'channel')
            ->distinct()
            ->get();

        if ($users->isEmpty()) {
            $this->info('No memories found to consolidate.');

            return 0;
        }

        $this->info("Found {$users->count()} unique users with memories.");

        if ($dryRun) {
            $this->warn('Dry run mode - no changes will be made');

            foreach ($users as $user) {
                $this->line("  - Sender: {$user->sender_id}, Channel: {$user->channel}");
            }

            return 0;
        }

        $totals = [
            'decayed' => 0,
            'pruned' => 0,
            'merged' => 0,
        ];

        $bar = $this->output->createProgressBar($users->count());
        $bar->start();

        foreach ($users as $user) {
            try {
                $channelEnum = ChannelEnum::from($user->channel);
                $result = $memory->consolidate($user->sender_id, $channelEnum);

                $totals['decayed'] += $result['decayed'];
                $totals['pruned'] += $result['pruned'];
                $totals['merged'] += $result['merged'];
            } catch (\Exception $e) {
                $this->error("Error consolidating for {$user->sender_id}: {$e->getMessage()}");
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
        $stats = Memory::forSender($senderId, $channel)
            ->selectRaw('
                COUNT(*) as total,
                AVG(importance) as avg_importance,
                SUM(CASE WHEN last_accessed_at < ? THEN 1 ELSE 0 END) as old_count,
                SUM(CASE WHEN importance < 0.1 AND access_count = 0 THEN 1 ELSE 0 END) as prune_candidates
            ', [now()->subDays(7)])
            ->first();

        $this->newLine();
        $this->line('Current memory statistics:');
        $this->line("  - Total memories: {$stats->total}");
        $this->line('  - Average importance: '.number_format($stats->avg_importance, 2));
        $this->line("  - Not accessed in 7+ days: {$stats->old_count}");
        $this->line("  - Prune candidates (low importance, unaccessed): {$stats->prune_candidates}");
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Channels\DiscordService;
use Illuminate\Console\Command;

class LaraClawDiscordCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'laraclaw:discord';

    /**
     * The console command description.
     */
    protected $description = 'Start the LaraClaw Discord bot client';

    /**
     * Execute the console command.
     */
    public function handle(DiscordService $discordService): int
    {
        $this->info('Starting Discord client...');

        try {
            $discordService->initialize();
            $discordService->startPolling();
        } catch (\Exception $e) {
            $this->error("Discord client error: {$e->getMessage()}");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

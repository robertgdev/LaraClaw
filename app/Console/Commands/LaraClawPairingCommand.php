<?php

namespace App\Console\Commands;

use App\Models\PairingEntry;
use Illuminate\Console\Command;

class LaraClawPairingCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'laraclaw:pairing
                            {action : Action to perform (list|approve|pending)}
                            {code? : Pairing code to approve}';

    /**
     * The console command description.
     */
    protected $description = 'Manage LaraClaw pairing entries';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'list' => $this->listEntries(),
            'approve' => $this->approveEntry(),
            'pending' => $this->listPending(),
            default => $this->showHelp(),
        };
    }

    /**
     * List all pairing entries.
     */
    protected function listEntries(): int
    {
        $entries = PairingEntry::orderBy('created_at', 'desc')->get();

        if ($entries->isEmpty()) {
            $this->info('No pairing entries found.');

            return Command::SUCCESS;
        }

        $this->info('Pairing Entries:');
        $this->info('');

        foreach ($entries as $entry) {
            $status = $entry->isApproved() ? '<info>APPROVED</info>' : '<comment>PENDING</comment>';
            $this->line(sprintf(
                '  [%s] %s - %s (%s) - %s',
                $entry->channel->value,
                $entry->sender,
                $entry->sender_id,
                $entry->code ?? 'N/A',
                $status
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * List pending pairing entries.
     */
    protected function listPending(): int
    {
        $entries = PairingEntry::pending()->orderBy('created_at', 'desc')->get();

        if ($entries->isEmpty()) {
            $this->info('No pending pairing entries.');

            return Command::SUCCESS;
        }

        $this->info('Pending Pairing Entries:');
        $this->info('');

        foreach ($entries as $entry) {
            $this->line(sprintf(
                '  [%s] %s (%s) - Code: <comment>%s</comment>',
                $entry->channel->value,
                $entry->sender,
                $entry->sender_id,
                $entry->code
            ));
        }

        $this->info('');
        $this->info('To approve: php artisan laraclaw:pairing approve <code>');

        return Command::SUCCESS;
    }

    /**
     * Approve a pairing entry.
     */
    protected function approveEntry(): int
    {
        $code = $this->argument('code');

        if (! $code) {
            $this->error('Please provide a pairing code.');
            $this->info('Usage: php artisan laraclaw:pairing approve <code>');

            return Command::FAILURE;
        }

        $entry = PairingEntry::approveByCode($code);

        if (! $entry) {
            $this->error("Pairing code not found or already approved: {$code}");

            return Command::FAILURE;
        }

        $this->info("Approved pairing for {$entry->sender} ({$entry->channel->value})");
        $this->info("Sender ID: {$entry->sender_id}");

        return Command::SUCCESS;
    }

    /**
     * Show help information.
     */
    protected function showHelp(): int
    {
        $this->info('LaraClaw Pairing Management');
        $this->info('');
        $this->info('Usage:');
        $this->info('  php artisan laraclaw:pairing list      - List all pairing entries');
        $this->info('  php artisan laraclaw:pairing pending   - List pending entries');
        $this->info('  php artisan laraclaw:pairing approve <code> - Approve a pairing code');

        return Command::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\Setting;
use App\Models\Team;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Safe\json_decode;
use function Safe\json_encode;

class LaraClawMigrateSettingsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'laraclaw:settings
                            {action : Action to perform (import|export|reset)}
                            {--file= : File path for import/export (default: storage/app/laraclaw/settings-backup.json)}
                            {--force : Force overwrite existing records}';

    /**
     * The console command description.
     */
    protected $description = 'Manage LaraClaw settings: import from file, export to file, or reset to defaults';

    protected SettingsService $settings;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->settings = app(SettingsService::class);
        $action = $this->argument('action');

        return match ($action) {
            'import' => $this->importFromFile(),
            'export' => $this->exportToFile(),
            'reset' => $this->resetToDefaults(),
            default => $this->showHelp(),
        };
    }

    /**
     * Import settings from a JSON file.
     */
    protected function importFromFile(): int
    {
        $file = $this->getFilePath();

        if (! File::exists($file)) {
            $this->error("File not found: {$file}");

            return Command::FAILURE;
        }

        $this->info("Importing settings from: {$file}");

        $data = json_decode(File::get($file), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON in file: '.json_last_error_msg());

            return Command::FAILURE;
        }

        // Check for existing data
        $existingAgents = Agent::count();
        $existingTeams = Team::count();
        $existingSettings = Setting::count();

        if (($existingAgents > 0 || $existingTeams > 0 || $existingSettings > 0) && ! $this->option('force')) {
            $this->warn('Database already contains:');
            $this->warn("  - {$existingAgents} agent(s)");
            $this->warn("  - {$existingTeams} team(s)");
            $this->warn("  - {$existingSettings} setting(s)");

            if (! $this->confirm('Do you want to continue and merge with existing records?')) {
                return Command::FAILURE;
            }
        }

        // Import using SettingsService
        $this->settings->import($data);

        $this->info('Settings imported successfully!');
        $this->info('  Agents: '.count($data['agents'] ?? []));
        $this->info('  Teams: '.count($data['teams'] ?? []));

        return Command::SUCCESS;
    }

    /**
     * Export settings to a JSON file.
     */
    protected function exportToFile(): int
    {
        $file = $this->getFilePath();

        $this->info("Exporting settings to: {$file}");

        $data = $this->settings->export();

        // Ensure directory exists
        $dir = dirname($file);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('Settings exported successfully!');
        $this->info('  Agents: '.count($data['agents']));
        $this->info('  Teams: '.count($data['teams']));

        return Command::SUCCESS;
    }

    /**
     * Reset settings to defaults.
     */
    protected function resetToDefaults(): int
    {
        $this->warn('This will reset all settings to defaults and delete all agents and teams!');

        if (! $this->confirm('Are you sure you want to continue?', false)) {
            $this->info('Reset cancelled.');

            return Command::SUCCESS;
        }

        // Clear all data
        Agent::query()->delete();
        Team::query()->delete();
        Setting::query()->delete();

        // Initialize defaults
        Setting::initializeDefaults();

        $this->info('Settings reset to defaults successfully!');

        return Command::SUCCESS;
    }

    /**
     * Show help message.
     */
    protected function showHelp(): int
    {
        $this->error('Unknown action: '.$this->argument('action'));
        $this->newLine();
        $this->info('Available actions:');
        $this->line('  <info>import</info>  - Import settings from a JSON file');
        $this->line('  <info>export</info>  - Export settings to a JSON file');
        $this->line('  <info>reset</info>   - Reset all settings to defaults');
        $this->newLine();
        $this->info('Examples:');
        $this->line('  php artisan laraclaw:settings export');
        $this->line('  php artisan laraclaw:settings import --file=/path/to/backup.json');
        $this->line('  php artisan laraclaw:settings reset');

        return Command::FAILURE;
    }

    /**
     * Get the file path for import/export.
     */
    protected function getFilePath(): string
    {
        return $this->option('file') ?: storage_path('app/laraclaw/settings-backup.json');
    }
}

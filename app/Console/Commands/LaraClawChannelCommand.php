<?php

namespace App\Console\Commands;

use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Safe\preg_match;
use function Safe\preg_replace;

class LaraClawChannelCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'laraclaw:channel
                            {action? : Action to perform (list|enable|disable|configure|show)}
                            {channel? : Channel ID (telegram|discord|whatsapp)}
                            {--token= : Bot token for enable}
                            {--polling= : Polling interval in seconds}
                            {--disable : Disable channel}
                            {--raw : Output raw channel IDs (for scripting)}';

    /**
     * The console command description.
     */
    protected $description = 'Manage LaraClaw channels';

    protected SettingsService $settings;

    /**
     * Channel registry with display names and config keys.
     *
     * @var array<string, array{display: string, token_key: string|null, token_env: string|null, help: string, requires_token: bool}>
     */
    protected array $channels = [
        'discord' => [
            'display' => 'Discord',
            'token_key' => 'DISCORD_BOT_TOKEN',
            'token_env' => 'DISCORD_BOT_TOKEN',
            'help' => 'Get one at: https://discord.com/developers/applications',
            'requires_token' => true,
        ],
        /*
        'telegram' => [
            'display' => 'Telegram',
            'token_key' => 'TELEGRAM_BOT_TOKEN',
            'token_env' => 'TELEGRAM_BOT_TOKEN',
            'help' => 'Create a bot via @BotFather on Telegram to get a token',
            'requires_token' => true,
        ],
        'whatsapp' => [
            'display' => 'WhatsApp',
            'token_key' => null,
            'token_env' => null,
            'help' => 'WhatsApp uses session-based authentication',
            'requires_token' => false,
        ],
        */
    ];

    /**
     * Execute the console command.
     */
    public function handle(SettingsService $settings): int
    {
        $this->settings = $settings;
        $action = $this->argument('action');

        // If no action provided, run interactive mode
        if (! $action) {
            return $this->interactiveMode();
        }

        // Validate action
        $validActions = ['list', 'enable', 'disable', 'configure', 'show'];
        if (! in_array($action, $validActions)) {
            $this->error("Invalid action '{$action}'. Valid actions: ".implode(', ', $validActions));

            return Command::FAILURE;
        }

        return match ($action) {
            'list' => $this->listChannels(),
            'enable' => $this->enableChannel(),
            'disable' => $this->disableChannel(),
            'configure' => $this->configureChannel(),
            'show' => $this->showChannel(),
        };
    }

    /**
     * Interactive mode - show menu and guide user.
     */
    protected function interactiveMode(): int
    {
        $this->displayHeader();

        $actions = [
            'list' => 'List configured channels',
            'enable' => 'Enable a channel',
            'disable' => 'Disable a channel',
            'configure' => 'Configure channel settings',
            'show' => 'Show channel details',
            'exit' => 'Exit',
        ];

        $choices = array_values($actions);
        $choice = $this->choice('What would you like to do?', $choices, 0);

        // Find the action key from the choice
        $actionKey = array_search($choice, $actions);

        return match ($actionKey) {
            'list' => $this->listChannels(),
            'enable' => $this->enableChannel(),
            'disable' => $this->disableChannel(),
            'configure' => $this->configureChannel(),
            'show' => $this->showChannel(),
            'exit' => Command::SUCCESS,
            default => Command::FAILURE,
        };
    }

    /**
     * Display the command header.
     */
    protected function displayHeader(): void
    {
        $this->newLine();
        $this->line('<fg=cyan>  '.str_repeat('=', 50).'</>');
        $this->line('<fg=green>    LaraClaw Channel Management</>');
        $this->line('<fg=cyan>  '.str_repeat('=', 50).'</>');
        $this->newLine();
    }

    /**
     * List all channels and their status.
     */
    protected function listChannels(): int
    {
        $enabledChannels = $this->settings->getEnabledChannels();

        // Raw output for scripting
        if ($this->option('raw')) {
            $this->line(implode(',', $enabledChannels));

            return Command::SUCCESS;
        }

        $this->info('Configured Channels:');
        $this->newLine();

        foreach ($this->channels as $channelId => $channel) {
            $isEnabled = in_array($channelId, $enabledChannels);
            $status = $isEnabled
                ? '<fg=green>✓ enabled</>'
                : '<fg=gray>✗ disabled</>';

            $pollingInterval = $this->settings->getPollingInterval($channelId);
            $pollingInfo = $isEnabled ? " (polling: {$pollingInterval}s)" : '';

            $this->line(sprintf(
                '  <info>%-12s</info> %s%s',
                $channel['display'],
                $status,
                $pollingInfo
            ));
        }

        $this->newLine();
        $this->line('  Run <info>php artisan laraclaw:channel show <channel></info> for details.');
        $this->line('  Run <info>php artisan laraclaw:channel enable <channel></info> to enable a channel.');

        return Command::SUCCESS;
    }

    /**
     * Enable a channel.
     */
    protected function enableChannel(): int
    {
        $channelId = $this->argument('channel');

        // If no channel provided, ask interactively
        if (! $channelId) {
            $disabledChannels = array_diff(
                array_keys($this->channels),
                $this->settings->getEnabledChannels()
            );

            if (empty($disabledChannels)) {
                $this->info('All channels are already enabled.');

                return Command::SUCCESS;
            }

            $choices = [];
            foreach ($disabledChannels as $id) {
                $choices[] = $this->channels[$id]['display'];
            }

            $choice = $this->choice('Which channel to enable?', $choices);
            $channelId = array_search($choice, array_map(fn ($c) => $this->channels[$c]['display'], $disabledChannels));
            $channelId = $disabledChannels[array_search($choice, $choices)];
        }

        // Validate channel
        if (! isset($this->channels[$channelId])) {
            $this->error("Invalid channel '{$channelId}'. Valid channels: ".implode(', ', array_keys($this->channels)));

            return Command::FAILURE;
        }

        $channel = $this->channels[$channelId];
        $enabledChannels = $this->settings->getEnabledChannels();

        // Check if already enabled
        if (in_array($channelId, $enabledChannels)) {
            $this->info("Channel '{$channel['display']}' is already enabled.");

            return Command::SUCCESS;
        }

        // Get token if required
        $token = $this->option('token');
        if ($channel['requires_token'] && ! $token) {
            $this->newLine();
            $this->line("  <fg=yellow>{$channel['help']}</>");

            // Check if token is already configured
            $existingToken = config("laraclaw.channels.{$channelId}.bot_token");
            $hasExistingToken = $existingToken && $existingToken !== 'your_token_here';

            if ($hasExistingToken) {
                $this->line("  <fg=green>A token is already configured for {$channel['display']}.</>");
                $this->line('  <fg=gray>Press Enter to keep existing token, or enter a new one:</>');
                $token = $this->secret("  Enter {$channel['display']} bot token");

                // If blank, use existing token
                if (empty($token)) {
                    $token = $existingToken;
                    $this->line("  <fg=green>\u{2713} Using existing token</>");
                }
            } else {
                // No existing token - must enter one
                $token = $this->secret("  Enter {$channel['display']} bot token");

                if (empty($token)) {
                    $this->error("Token is required for {$channel['display']}.");
                    $this->info("  Get a token: {$channel['help']}");

                    return Command::FAILURE;
                }
            }
        }

        // Get polling interval
        $pollingInterval = $this->option('polling');
        if (! $pollingInterval) {
            $pollingInterval = $this->ask('Polling interval (seconds)', '5');
        }

        if (! is_numeric($pollingInterval) || (int) $pollingInterval < 1) {
            $this->warn('Invalid interval. Using default: 5 seconds');
            $pollingInterval = 5;
        }

        // Save token to .env if required and a new token was provided
        if ($channel['requires_token'] && $token) {
            $this->updateEnvFile($channel['token_env'], $token);
        }

        // Enable channel in settings
        $enabledChannels[] = $channelId;
        $this->settings->set('channels.enabled', $enabledChannels);
        $this->settings->set("channels.{$channelId}.polling_interval", (int) $pollingInterval);

        $this->newLine();
        $this->info("Channel '{$channel['display']}' enabled successfully.");
        $this->info("  Polling interval: {$pollingInterval}s");

        return Command::SUCCESS;
    }

    /**
     * Disable a channel.
     */
    protected function disableChannel(): int
    {
        $channelId = $this->argument('channel');

        // If no channel provided, ask interactively
        if (! $channelId) {
            $enabledChannels = $this->settings->getEnabledChannels();

            if (empty($enabledChannels)) {
                $this->info('No channels are enabled.');

                return Command::SUCCESS;
            }

            $choices = [];
            foreach ($enabledChannels as $id) {
                $choices[] = $this->channels[$id]['display'];
            }

            $choice = $this->choice('Which channel to disable?', $choices);
            $channelId = $enabledChannels[array_search($choice, $choices)];
        }

        // Validate channel
        if (! isset($this->channels[$channelId])) {
            $this->error("Invalid channel '{$channelId}'. Valid channels: ".implode(', ', array_keys($this->channels)));

            return Command::FAILURE;
        }

        $channel = $this->channels[$channelId];
        $enabledChannels = $this->settings->getEnabledChannels();

        // Check if already disabled
        if (! in_array($channelId, $enabledChannels)) {
            $this->info("Channel '{$channel['display']}' is already disabled.");

            return Command::SUCCESS;
        }

        // Confirm
        if (! $this->confirm("Disable {$channel['display']}?", true)) {
            $this->info('Cancelled.');

            return Command::SUCCESS;
        }

        // Disable channel in settings
        $enabledChannels = array_filter($enabledChannels, fn ($c) => $c !== $channelId);
        $this->settings->set('channels.enabled', array_values($enabledChannels));

        $this->info("Channel '{$channel['display']}' disabled.");

        return Command::SUCCESS;
    }

    /**
     * Configure a channel's settings.
     */
    protected function configureChannel(): int
    {
        $channelId = $this->argument('channel');

        // If no channel provided, ask interactively
        if (! $channelId) {
            $enabledChannels = $this->settings->getEnabledChannels();

            if (empty($enabledChannels)) {
                $this->info('No channels are enabled. Enable a channel first.');

                return Command::SUCCESS;
            }

            $choices = [];
            foreach ($enabledChannels as $id) {
                $choices[] = $this->channels[$id]['display'];
            }

            $choice = $this->choice('Which channel to configure?', $choices);
            $channelId = $enabledChannels[array_search($choice, $choices)];
        }

        // Validate channel
        if (! isset($this->channels[$channelId])) {
            $this->error("Invalid channel '{$channelId}'. Valid channels: ".implode(', ', array_keys($this->channels)));

            return Command::FAILURE;
        }

        $channel = $this->channels[$channelId];

        // Get current settings
        $currentPolling = $this->settings->getPollingInterval($channelId);

        $this->info("Configuring {$channel['display']}:");
        $this->info("  Current polling interval: {$currentPolling}s");

        // Show token status if applicable
        if ($channel['requires_token']) {
            $existingToken = config("laraclaw.channels.{$channelId}.bot_token");
            $hasExistingToken = $existingToken && $existingToken !== 'your_token_here';
            $tokenStatus = $hasExistingToken ? '<fg=green>configured</>' : '<fg=red>not configured</>';
            $this->info("  Token: {$tokenStatus}");
        }

        $this->newLine();

        // Ask about updating token if channel requires one
        if ($channel['requires_token']) {
            $existingToken = config("laraclaw.channels.{$channelId}.bot_token");
            $hasExistingToken = $existingToken && $existingToken !== 'your_token_here';

            if ($hasExistingToken) {
                $this->line('  <fg=gray>Press Enter to keep existing token, or enter a new one:</>');
            } else {
                $this->line('  <fg=yellow>No token configured. Enter one now or leave blank to skip:</>');
            }

            $token = $this->secret("  Enter {$channel['display']} bot token");

            // If a new token was entered, update it
            if (! empty($token)) {
                $this->updateEnvFile($channel['token_env'], $token);
            } elseif (! $hasExistingToken) {
                $this->warn('  No token entered. Token remains unconfigured.');
            }
        }

        // Get new polling interval
        $pollingInterval = $this->option('polling');
        if (! $pollingInterval) {
            $pollingInterval = $this->ask('New polling interval (seconds)', (string) $currentPolling);
        }

        if (! is_numeric($pollingInterval) || (int) $pollingInterval < 1) {
            $this->error('Invalid interval. Must be a positive number.');

            return Command::FAILURE;
        }

        // Update settings
        $this->settings->set("channels.{$channelId}.polling_interval", (int) $pollingInterval);

        $this->newLine();
        $this->info("{$channel['display']} configured successfully.");
        $this->info("  Polling interval: {$pollingInterval}s");

        return Command::SUCCESS;
    }

    /**
     * Show channel details.
     */
    protected function showChannel(): int
    {
        $channelId = $this->argument('channel');

        // If no channel provided, ask interactively
        if (! $channelId) {
            $enabledChannels = $this->settings->getEnabledChannels();

            if (empty($enabledChannels)) {
                $this->info('No channels are enabled.');

                return Command::SUCCESS;
            }

            $choices = [];
            foreach ($enabledChannels as $id) {
                $choices[] = $this->channels[$id]['display'];
            }

            $choice = $this->choice('Which channel to show?', $choices);
            $channelId = $enabledChannels[array_search($choice, $choices)];
        }

        // Validate channel
        if (! isset($this->channels[$channelId])) {
            $this->error("Invalid channel '{$channelId}'. Valid channels: ".implode(', ', array_keys($this->channels)));

            return Command::FAILURE;
        }

        $channel = $this->channels[$channelId];
        $enabledChannels = $this->settings->getEnabledChannels();
        $isEnabled = in_array($channelId, $enabledChannels);

        $this->info("Channel: {$channel['display']}");
        $this->newLine();
        $this->info('  Status: '.($isEnabled ? 'enabled' : 'disabled'));

        if ($isEnabled) {
            $pollingInterval = $this->settings->getPollingInterval($channelId);
            $this->info("  Polling interval: {$pollingInterval}s");
        }

        $this->info('  Requires token: '.($channel['requires_token'] ? 'yes' : 'no'));

        if ($channel['requires_token']) {
            $token = config("laraclaw.channels.{$channelId}.bot_token");
            $tokenStatus = $token && $token !== 'your_token_here'
                ? 'configured'
                : '<fg=red>not configured</>';
            $this->info("  Token: {$tokenStatus}");
        }

        $this->newLine();
        $this->line("  <fg=yellow>Help: {$channel['help']}</>");

        return Command::SUCCESS;
    }

    /**
     * Update a value in the .env file.
     */
    protected function updateEnvFile(string $key, string $value): void
    {
        $envPath = base_path('.env');
        $envContent = File::exists($envPath) ? File::get($envPath) : '';

        $pattern = '/^'.preg_quote($key, '/').'=.*/m';
        $replacement = $key.'='.$value;

        if (preg_match($pattern, $envContent)) {
            $envContent = preg_replace($pattern, $replacement, $envContent);
        } else {
            // Add new variable at the end
            $envContent .= "\n".$replacement;
        }

        File::put($envPath, $envContent);
        $this->line("  <fg=green>\u{2713} Updated {$key} in .env</>");
    }
}

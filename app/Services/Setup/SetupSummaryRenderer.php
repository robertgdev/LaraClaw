<?php

declare(strict_types=1);

namespace App\Services\Setup;

use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

/**
 * Renders configuration summaries and manages confirmation during the setup wizard.
 *
 * Handles ASCII-box display of collected configuration, .env change previews,
 * and final completion messages.
 */
class SetupSummaryRenderer
{
    /**
     * Display configuration summary.
     *
     * @param  Command  $command  The command instance for output
     * @param  array  $config  The collected configuration
     * @param  array<string, array{display: string}>  $channels  Channel registry
     * @param  array<string, array{display: string}>  $providers  Provider registry
     */
    public function displaySummary(Command $command, array $config, array $channels, array $providers): void
    {
        $command->newLine();
        $command->line('<fg=cyan>  '.str_repeat('=', 60).'</>');
        $command->line('<fg=green>    Configuration Summary</>');
        $command->line('<fg=cyan>  '.str_repeat('=', 60).'</>');
        $command->newLine();

        // Channels
        $channelNames = implode(', ', array_map(
            fn ($c) => $channels[$c]['display'] ?? $c,
            $config['channels']
        ));
        $command->line("  <info>Channels:</info>       {$channelNames}");

        // Provider & Model
        $provider = $providers[$config['provider']]['display'] ?? $config['provider'];
        $command->line("  <info>Provider:</info>       {$provider}");
        $command->line("  <info>Model:</info>          {$config['model']}");

        // Heartbeat
        $command->line("  <info>Heartbeat:</info>      {$config['heartbeat']}s");

        // Workspace
        $command->line("  <info>Workspace:</info>     {$config['workspace_path']}");

        // Default Agent
        $command->line("  <info>Default Agent:</info> @{$config['default_agent_id']} ({$config['default_agent_name']})");

        // Additional Agents
        if (! empty($config['additional_agents'])) {
            $command->newLine();
            $command->line('  <info>Additional Agents:</info>');
            foreach ($config['additional_agents'] as $agent) {
                $command->line("    - @{$agent['agent_id']} ({$agent['name']}) [{$agent['provider']}/{$agent['model']}]");
            }
        }

        $command->newLine();
        $command->line('<fg=cyan>  '.str_repeat('-', 60).'</>');
    }

    /**
     * Display .env changes preview and confirm.
     *
     * @return bool True if user confirmed, false if cancelled
     */
    public function displayEnvChangesAndConfirm(Command $command, array $envChanges): bool
    {
        $command->newLine();
        warning('The following .env variables will be updated:');
        $command->newLine();

        $envWriter = new SetupEnvWriter;
        foreach ($envChanges as $key => $value) {
            $displayValue = $envWriter->shouldMaskValue($key)
                ? str_repeat('*', min(strlen($value), 8))
                : $value;
            $command->line("    <comment>{$key}</comment> = {$displayValue}");
        }

        $command->newLine();
        $command->line('<fg=cyan>  '.str_repeat('-', 60).'</>');
        $command->newLine();

        return confirm('Write these changes to .env?', true);
    }

    /**
     * Display completion message.
     */
    public function displayCompletion(Command $command, array $config): void
    {
        $command->newLine();
        $command->line('<fg=green>  '.str_repeat('=', 60).'</>');
        $command->line('<fg=green>    Setup Complete!</>');
        $command->line('<fg=green>  '.str_repeat('=', 60).'</>');
        $command->newLine();
    }

    /**
     * Display generated API keys.
     */
    public function displayApiKeys(Command $command, array $config): void
    {
        if (! empty($config['generated_api_key'])) {
            $command->line('<fg=yellow>  ┌──────────────────────────────────────────────────────────┐</>');
            $command->line('<fg=yellow>  │</> <fg=white;options=bold>  Server API Token (save this - shown once only)</>          <fg=yellow>│</>');
            $command->line('<fg=yellow>  │</>                                                          <fg=yellow>│</>');
            $command->line("<fg=yellow>  │</>   <fg=cyan>{$config['generated_api_key']}          <fg=yellow>│</>");
            $command->line('<fg=yellow>  │</>                                                          <fg=yellow>│</>');
            $command->line('<fg=yellow>  │</>   <fg=gray>Use this token to authenticate API requests.</>        <fg=yellow>│</>');
            $command->line('<fg=yellow>  │</>   <fg=gray>Stored in .env as Laraclaw_SERVER_API_KEY</>            <fg=yellow>│</>');
            $command->line('<fg=yellow>  └──────────────────────────────────────────────────────────┘</>');
            $command->newLine();
        }

        if (! empty($config['generated_rest_api_key'])) {
            $command->line('<fg=yellow>  ┌──────────────────────────────────────────────────────────┐</>');
            $command->line('<fg=yellow>  │</> <fg=white;options=bold>  REST API Token (save this - shown once only)</>           <fg=yellow>│</>');
            $command->line('<fg=yellow>  │</>                                                          <fg=yellow>│</>');
            $command->line("<fg=yellow>  │</>   <fg=cyan>{$config['generated_rest_api_key']}          <fg=yellow>│</>");
            $command->line('<fg=yellow>  │</>                                                          <fg=yellow>│</>');
            $command->line('<fg=yellow>  │</>   <fg=gray>Use this token for HTTP API requests (ChatController).</> <fg=yellow>│</>');
            $command->line('<fg=yellow>  │</>   <fg=gray>Stored in .env as LARACLAW_REST_API_KEY</>              <fg=yellow>│</>');
            $command->line('<fg=yellow>  └──────────────────────────────────────────────────────────┘</>');
            $command->newLine();
        }
    }

    /**
     * Display next steps.
     */
    public function displayNextSteps(Command $command): void
    {
        $command->info('  You can manage agents later with:');
        $command->line('    <info>php artisan laraclaw:agent list</info>    - List agents');
        $command->line('    <info>php artisan laraclaw:agent add</info>     - Add more agents');
        $command->newLine();
        \Laravel\Prompts\info('You can now start LaraClaw:');
        $command->line('    <info>php artisan queue:work</info>              - Start queue worker');
        $command->line('    <info>php artisan laraclaw:discord</info>        - Start Discord client');
        // $command->line('    <info>php artisan laraclaw:telegram</info>       - Start Telegram client');
        // $command->line('    <info>php artisan laraclaw:whatsapp</info>       - Start Whatsapp client');
    }
}

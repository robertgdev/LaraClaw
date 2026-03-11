<?php

namespace App\Console\Commands;

use App\Services\SettingsService;
use App\Services\Setup\LlmConnectionTester;
use App\Services\Setup\SetupAgentConfigurator;
use App\Services\Setup\SetupChannelConfigurator;
use App\Services\Setup\SetupEnvWriter;
use App\Services\Setup\SetupProviderConfigurator;
use App\Services\Setup\SetupSummaryRenderer;
use App\Services\Setup\SetupWorkspaceInitializer;
use App\Services\SkillClassificationService;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class LaraClawSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'laraclaw:setup
                            {--reset : Reset all configuration}';

    /**
     * The console command description.
     */
    protected $description = 'LaraClaw Setup Wizard - Configure your AI assistant';

    /**
     * Collected configuration values.
     *
     * @var array<string, mixed>
     */
    protected array $config = [];

    protected SetupChannelConfigurator $channelConfigurator;

    protected SetupProviderConfigurator $providerConfigurator;

    protected SetupAgentConfigurator $agentConfigurator;

    protected SetupSummaryRenderer $summaryRenderer;

    public function __construct()
    {
        parent::__construct();
        $this->channelConfigurator = new SetupChannelConfigurator;
        $this->providerConfigurator = new SetupProviderConfigurator;
        $this->agentConfigurator = new SetupAgentConfigurator;
        $this->summaryRenderer = new SetupSummaryRenderer;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->displayHeader();

        if ($this->option('reset')) {
            warning('Reset mode: All existing configuration will be replaced.');
            if (! confirm('Continue with reset?', true)) {
                info('Setup cancelled.');

                return Command::SUCCESS;
            }
        }

        // Step 1: Channel Selection
        $channelConfig = $this->channelConfigurator->configure();
        $this->config = array_merge($this->config, $channelConfig);

        // Step 2: Provider Selection
        $providerConfig = $this->providerConfigurator->configure();
        $this->config = array_merge($this->config, $providerConfig);

        // Step 3: Heartbeat Interval
        $this->config['heartbeat'] = $this->providerConfigurator->configureHeartbeat();

        // Step 4: Workspace Configuration
        $workspaceConfig = $this->agentConfigurator->configureWorkspace();
        $this->config = array_merge($this->config, $workspaceConfig);

        // Step 5: Default Agent
        $agentConfig = $this->agentConfigurator->configureDefaultAgent();
        $this->config = array_merge($this->config, $agentConfig);

        // Step 6: Additional Agents (optional)
        $this->configureAdditionalAgents();

        // Step 7: Review and Confirm
        $this->displaySummary();

        return Command::SUCCESS;
    }

    /**
     * Display the setup wizard header.
     */
    protected function displayHeader(): void
    {
        $this->newLine();
        $this->line('<fg=cyan>  '.str_repeat('=', 60).'</>');
        $this->line('<fg=green>    LaraClaw - Setup Wizard</>');
        $this->line('<fg=cyan>  '.str_repeat('=', 60).'</>');
        $this->newLine();
    }

    /**
     * Configure additional agents (optional).
     */
    protected function configureAdditionalAgents(): void
    {
        $this->line('<fg=cyan>  '.str_repeat('=', 60).'</>');
        $this->line('<fg=green>    Additional Agents (Optional)</>');
        $this->line('<fg=cyan>  '.str_repeat('=', 60).'</>');
        $this->newLine();

        $this->line('  You can set up multiple agents with different roles and models.');
        $this->line('  Users route messages with <info>@agent_id message</info> in chat.');
        $this->newLine();

        $this->config['additional_agents'] = $this->agentConfigurator->configureAdditionalAgents(
            $this->config['default_agent_id']
        );

        // Ask which agent should be the default
        $this->newLine();
        $this->line('<fg=cyan>  '.str_repeat('-', 60).'</>');
        $this->config['selected_default_agent_id'] = $this->agentConfigurator->selectDefaultAgent($this->config);
    }

    /**
     * Display configuration summary and confirm.
     */
    protected function displaySummary(): void
    {
        $this->summaryRenderer->displaySummary(
            $this,
            $this->config,
            $this->channelConfigurator->getChannels(),
            $this->providerConfigurator->getProviders()
        );

        // LLM Connection Test
        $this->testLlmConnection();

        $this->newLine();
        $this->line('<fg=cyan>  '.str_repeat('-', 60).'</>');
        $this->newLine();

        // .env changes preview and confirmation
        $envChanges = $this->getEnvChanges();
        if (! $this->summaryRenderer->displayEnvChangesAndConfirm($this, $envChanges)) {
            info('Setup cancelled. No changes were made.');

            return;
        }

        $this->writeEnvFile();
        $this->createDirectories();
        $this->createAgents();

        $this->summaryRenderer->displayCompletion($this, $this->config);

        // Copy template files to storage (last step)
        $this->copyTemplateFilesToStorage();

        // Skill Pre-Classification
        $this->offerSkillPreClassification();

        // Show generated API keys
        $this->summaryRenderer->displayApiKeys($this, $this->config);

        // Show next steps
        $this->summaryRenderer->displayNextSteps($this);
    }

    /**
     * Test the LLM connection with the configured provider and model.
     */
    protected function testLlmConnection(): void
    {
        $providers = $this->providerConfigurator->getProviders();
        $provider = $providers[$this->config['provider']] ?? null;

        if (! $provider) {
            warning('Could not test LLM: Provider not found in config.');

            return;
        }

        if ($provider['api_key'] !== null && empty($this->config['api_key'])) {
            warning("Skipping LLM test: No API key provided for {$provider['display']}.");
            $this->line('  <fg=gray>You can test the connection later after setting the API key in .env</>');

            return;
        }

        if (! confirm('Test LLM connection with your configuration?', true)) {
            info('Skipping LLM connection test.');

            return;
        }

        $this->newLine();
        info("Testing connection to {$provider['display']} ({$this->config['model']})...");

        $tester = new LlmConnectionTester;
        $result = spin(
            callback: fn () => $tester->test(
                $this->config['provider'],
                $this->config['model'],
                $this->config['api_key'] ?? null,
                $provider['api_key']
            ),
            message: 'Sending test message...'
        );

        $this->newLine();

        if ($result['success']) {
            $this->line('<fg=green>  ┌──────────────────────────────────────────────────────────┐</>');
            $this->line('<fg=green>  │</> <fg=white;options=bold>  LLM Connection Successful!</>                             <fg=green>│</>');
            $this->line('<fg=green>  │</>                                                          <fg=green>│</>');

            $displayResponse = strlen($result['response']) > 50
                ? substr($result['response'], 0, 50).'...'
                : $result['response'];
            $displayResponse = str_replace(["\n", "\r"], ' ', $displayResponse);

            $this->line("<fg=green>  │</>   <fg=cyan>Response:</> {$displayResponse}");
            $this->line('<fg=green>  │</>                                                          <fg=green>│</>');
            $this->line('<fg=green>  └──────────────────────────────────────────────────────────┘</>');
        } else {
            $this->line('<fg=red>  ┌──────────────────────────────────────────────────────────┐</>');
            $this->line('<fg=red>  │</> <fg=white;options=bold>  LLM Connection Failed!</>                                      <fg=red>│</>');
            $this->line('<fg=red>  │</>                                                          <fg=red>│</>');

            $errorMsg = strlen($result['error']) > 48
                ? substr($result['error'], 0, 48).'...'
                : $result['error'];

            $this->line("<fg=red>  │</>   <fg=yellow>Error:</> {$errorMsg}");
            $this->line('<fg=red>  │</>                                                          <fg=red>│</>');
            $this->line('<fg=red>  │</>   <fg=gray>Please check your API key and try again.</>               <fg=red>│</>');
            $this->line('<fg=red>  └──────────────────────────────────────────────────────────┘</>');

            if (! confirm('Continue with setup anyway?', true)) {
                info('Setup cancelled. Please fix the API key and run setup again.');
                exit(1);
            }
        }
    }

    /**
     * Copy template files from resources/laraclaw to storage.
     */
    protected function copyTemplateFilesToStorage(): void
    {
        $this->line('<fg=cyan>  '.str_repeat('-', 60).'</>');
        $this->newLine();

        $initializer = new SetupWorkspaceInitializer;
        $result = $initializer->copyTemplateFilesToStorage($this->config['workspace_name']);

        foreach ($result['messages'] as $message) {
            $this->line("  <fg=green>✓</> {$message}");
        }

        $workspaceName = $this->config['workspace_name'];
        $this->newLine();
        if ($result['copied'] > 0) {
            $this->line("<fg=green>  ✓ Template files copied to storage/app/$workspaceName</>");
            $this->line('  <fg=gray>You can customize these files without losing changes on update.</>');
        }
        if ($result['skipped'] > 0) {
            $this->line("  <fg=gray>Skipped {$result['skipped']} existing file(s) - your customizations preserved.</>");
        }

        // Create symlink
        $this->createAgentsSymlink();
    }

    /**
     * Create a symlink from .agents to storage/app/{workspace}/agents.
     */
    protected function createAgentsSymlink(): void
    {
        $this->newLine();

        $initializer = new SetupWorkspaceInitializer;
        $result = $initializer->createAgentsSymlink($this->config['workspace_name']);

        if ($result['created']) {
            $this->line("<fg=green>  ✓ {$result['message']}</>");
            $this->line('  <fg=gray>Skills are now accessible from the Laravel root directory.</>');
        } else {
            $this->line("  <fg=gray>{$result['message']}</>");
        }
    }

    /**
     * Offer to pre-classify skills.
     */
    protected function offerSkillPreClassification(): void
    {
        $this->line('<fg=cyan>  '.str_repeat('-', 60).'</>');
        $this->newLine();

        $this->line('  You now have the option to classify your installed skills.');
        $this->line('  This will populate the intent cache enabling faster skill');
        $this->line('  matching and saving tokens.');
        $this->newLine();

        warning('  This will consume LLM tokens!');
        $this->newLine();

        if (! confirm('Would you like to perform this step now?', false)) {
            $this->newLine();
            info('You can always (re)classify installed skills using the "php artisan laraclaw:skill" command');

            return;
        }

        $this->newLine();
        info('Classifying skills...');
        $this->newLine();

        try {
            $service = app(SkillClassificationService::class);

            $progressCallback = function (string $skillName, int $mappingsCount, int $total, int $current) {
                $status = $mappingsCount > 0
                    ? "<fg=green>✓</> {$mappingsCount} intents"
                    : '<fg=yellow>✗</> no intents';
                $this->line("  <fg=cyan>[{$current}/{$total}]</> <comment>{$skillName}</comment> - {$status}");
            };

            $result = $service->classifyAllSkills(true, $progressCallback);

            $this->newLine();
            $this->line('<fg=green>  ┌──────────────────────────────────────────────────────────┐</>');
            $this->line('<fg=green>  │</> <fg=white;options=bold>  Skill Classification Complete!</>                            <fg=green>│</>');
            $this->line('<fg=green>  │</>                                                          <fg=green>│</>');
            $this->line("<fg=green>  │</>   <fg=cyan>Skills processed:</>    {$result->skillsProcessed}                              <fg=green>│</>");
            $this->line("<fg=green>  │</>   <fg=cyan>Mappings generated:</>  {$result->mappingsGenerated}                            <fg=green>│</>");
            $this->line("<fg=green>  │</>   <fg=cyan>Mappings stored:</>     {$result->mappingsStored}                             <fg=green>│</>");
            $this->line('<fg=green>  │</>                                                          <fg=green>│</>');
            $this->line('<fg=green>  └──────────────────────────────────────────────────────────┘</>');
            $this->newLine();

            if ($result->errors) {
                $this->newLine();
                warning('Some errors occurred:');
                foreach ($result->errors as $error) {
                    $this->line("  - {$error}");
                }
            }
        } catch (\Exception $e) {
            $this->newLine();
            $this->line('<fg=red>  ┌──────────────────────────────────────────────────────────┐</>');
            $this->line('<fg=red>  │</> <fg=white;options=bold>  Skill Classification Failed!</>                                <fg=red>│</>');
            $this->line('<fg=red>  │</>                                                          <fg=red>│</>');

            $errorMsg = $e->getMessage();
            $maxLen = 48;
            if (strlen($errorMsg) > $maxLen) {
                $errorMsg = substr($errorMsg, 0, $maxLen).'...';
            }

            $this->line("<fg=red>  │</>   <fg=yellow>Error:</> {$errorMsg}");
            $this->line('<fg=red>  │</>                                                          <fg=red>│</>');
            $this->line('<fg=red>  │</>   <fg=gray>You can run "php artisan laraclaw:skill" later.</>          <fg=red>│</>');
            $this->line('<fg=red>  └──────────────────────────────────────────────────────────┘</>');
        }
    }

    /**
     * Get all .env changes as key-value pairs.
     *
     * @return array<string, string>
     */
    protected function getEnvChanges(): array
    {
        $changes = [];

        // Tokens
        foreach ($this->config['tokens'] as $key => $value) {
            $changes[$key] = $value;
        }

        // Provider & Model
        $changes['LARACLAW_DEFAULT_PROVIDER'] = $this->config['provider'];

        // API key (only if provider requires one)
        if ($this->config['api_key_name'] !== null) {
            $changes[$this->config['api_key_name']] = $this->config['api_key'] ?? '';
        }

        // Provider-specific model
        $modelKey = strtoupper($this->config['provider']).'_MODEL';
        $changes[$modelKey] = $this->config['model'];

        // Heartbeat
        $changes['LARACLAW_HEARTBEAT_INTERVAL'] = (string) $this->config['heartbeat'];

        // Workspace
        $changes['LARACLAW_WORKSPACE_NAME'] = $this->config['workspace_name'];
        $changes['LARACLAW_WORKSPACE_PATH'] = $this->config['workspace_path'];

        // Daemon API Token
        $existingToken = env('LARACLAW_SERVER_API_KEY');
        if ($this->option('reset') || empty($existingToken)) {
            $envWriter = new SetupEnvWriter;
            $changes['LARACLAW_SERVER_API_KEY'] = $envWriter->generateApiToken();
            $this->config['generated_api_key'] = $changes['LARACLAW_SERVER_API_KEY'];
        }

        // REST API Token
        $existingRestToken = env('LARACLAW_REST_API_KEY');
        if ($this->option('reset') || empty($existingRestToken)) {
            $envWriter = new SetupEnvWriter;
            $changes['LARACLAW_REST_API_KEY'] = $envWriter->generateApiToken();
            $this->config['generated_rest_api_key'] = $changes['LARACLAW_REST_API_KEY'];
        }

        return $changes;
    }

    /**
     * Write changes to .env file.
     */
    protected function writeEnvFile(): void
    {
        $envWriter = new SetupEnvWriter;
        $envWriter->write($this->getEnvChanges());
        $this->line("<fg=green>  \u{2713} Configuration written to .env</>");
    }

    /**
     * Create necessary directories.
     */
    protected function createDirectories(): void
    {
        $initializer = new SetupWorkspaceInitializer;
        $messages = $initializer->createDirectories(
            $this->config['workspace_path'],
            $this->config['default_agent_id'],
            $this->config['additional_agents']
        );

        foreach ($messages as $message) {
            $this->line("<fg=green>  \u{2713} {$message}</>");
        }
    }

    /**
     * Create agents and settings in database.
     */
    protected function createAgents(): void
    {
        $settings = app(SettingsService::class);

        $defaultAgentId = $this->config['selected_default_agent_id'] ?? $this->config['default_agent_id'];

        // Store scalar settings
        $settings->setMany([
            'workspace.path' => $this->config['workspace_path'],
            'workspace.name' => $this->config['workspace_name'],
            'channels.enabled' => $this->config['channels'],
            'models.provider' => $this->config['provider'],
            'models.'.$this->config['provider'].'.model' => $this->config['model'],
            'monitoring.heartbeat_interval' => $this->config['heartbeat'],
            'agents.default_agent_id' => $defaultAgentId,
        ]);

        foreach ($this->config['polling_intervals'] as $channelId => $interval) {
            $settings->set("channels.{$channelId}.polling_interval", $interval);
        }

        $this->line("<fg=green>  \u{2713} Saved settings to database</>");

        // Default agent
        $defaultAgentDir = $this->config['workspace_path'].'/'.$this->config['default_agent_id'];

        $settings->setAgent($this->config['default_agent_id'], [
            'name' => $this->config['default_agent_name'],
            'provider' => $this->config['provider'],
            'model' => $this->config['model'],
            'working_directory' => $defaultAgentDir,
            'is_active' => true,
        ]);

        $this->line("<fg=green>  \u{2713} Created default agent: @{$this->config['default_agent_id']}</>");

        // Additional agents
        foreach ($this->config['additional_agents'] as $agent) {
            $agentDir = $this->config['workspace_path'].'/'.$agent['agent_id'];

            $settings->setAgent($agent['agent_id'], [
                'name' => $agent['name'],
                'provider' => $agent['provider'],
                'model' => $agent['model'],
                'working_directory' => $agentDir,
                'is_active' => true,
            ]);

            $this->line("<fg=green>  \u{2713} Created agent: @{$agent['agent_id']}</>");
        }

        if ($defaultAgentId !== $this->config['default_agent_id']) {
            $this->line("<fg=green>  \u{2713} Routing default agent: @{$defaultAgentId}</>");
        }
    }
}

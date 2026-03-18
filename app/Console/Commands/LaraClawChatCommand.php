<?php

namespace App\Console\Commands;

use App\Enums\ChannelEnum;
use App\Models\Conversation;
use App\Services\AgentInvokerService;
use App\Services\Chat\ChatMessageProcessor;
use App\Services\Chat\ChatShellCommandHandler;
use App\Services\Chat\ChatShellRenderer;
use App\Services\ConversationHistoryService;
use App\Services\MemoryEngineService;
use App\Services\ResponseParserService;
use App\Services\SessionService;
use App\Services\SettingsService;
use App\Services\Shell\ShellHistoryManager;
use App\Services\SkillSearchService;
use Illuminate\Console\Command;

class LaraClawChatCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'laraclaw:chat
                            {--agent= : Default agent ID to route to}
                            {--team= : Default team ID to route to}
                            {--reset : Reset conversation context on start}
                            {--history= : Path to history file (default: ~/.laraclaw/shell_history)}';

    /**
     * The console command description.
     */
    protected $description = 'Start an interactive shell for sending commands to agents';

    /**
     * Shell state
     */
    protected string $defaultAgentId;

    protected ?string $defaultTeamId = null;

    protected bool $shouldReset = false;

    protected ShellHistoryManager $historyManager;

    /**
     * Session state
     */
    protected ?Conversation $currentSession = null;

    protected string $senderId = 'cli-user';

    /**
     * Extracted service classes
     */
    protected ChatShellCommandHandler $commandHandler;

    protected ChatMessageProcessor $messageProcessor;

    protected ChatShellRenderer $renderer;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $settings = app(SettingsService::class);
        $invokerService = app(AgentInvokerService::class);
        $chatHistoryService = app(ConversationHistoryService::class);
        $sessionService = app(SessionService::class);
        $skillService = app(SkillSearchService::class);
        $responseParser = app(ResponseParserService::class);
        $memoryService = app(MemoryEngineService::class);

        // Wire up skill service and response parser to invoker
        $invokerService->setSkillService($skillService);
        $invokerService->setResponseParser($responseParser);
        $invokerService->setMemoryService($memoryService);
        $invokerService->setChannel(ChannelEnum::CLI);
        $invokerService->setSenderId($this->senderId);

        // Build extracted service instances
        $this->renderer = new ChatShellRenderer($settings);
        $this->commandHandler = new ChatShellCommandHandler($settings, $sessionService, $this->renderer);
        $this->messageProcessor = new ChatMessageProcessor($settings, $invokerService, $chatHistoryService, $this->renderer);
        $this->messageProcessor->setMemoryService($memoryService);
        $this->messageProcessor->setSenderId($this->senderId);

        // Ensure settings are initialized
        $agents = $settings->getAgents();
        if ($agents->isEmpty()) {
            $this->warn('No agents configured.');
            $this->info('Run `php artisan laraclaw:setup` to configure LaraClaw.');
            $this->newLine();

            return Command::FAILURE;
        }

        // Set up defaults
        $this->defaultAgentId = $this->option('agent') ?? $settings->getDefaultAgentId();
        $this->defaultTeamId = $this->option('team');
        $this->shouldReset = (bool) $this->option('reset');

        // Set up history manager
        $historyFile = $this->option('history') ?? getenv('HOME').'/.laraclaw/shell_history';
        $this->historyManager = new ShellHistoryManager($historyFile);
        $this->historyManager->load();

        // Get or create active session
        $this->currentSession = $sessionService->getOrCreateActiveSession(
            ChannelEnum::CLI,
            $this->senderId,
            'shell-user'
        );

        // Display welcome banner
        $this->renderer->displayWelcome($this->output, $this->defaultAgentId, $this->defaultTeamId);

        // Start the REPL loop
        return $this->repl();
    }

    /**
     * Start the Read-Eval-Print Loop.
     */
    protected function repl(): int
    {
        $readlineAvailable = function_exists('readline');

        while (true) {
            // Build prompt with colors for display
            $prompt = '<fg=green>laraclaw</>';
            if ($this->defaultTeamId) {
                $prompt .= " <fg=magenta>@{$this->defaultTeamId}</>";
            }
            $prompt .= " <fg=yellow>@{$this->defaultAgentId}</>";
            $prompt .= ' <fg=white>></> ';

            // Build plain prompt for readline (no formatting)
            $plainPrompt = 'laraclaw';
            if ($this->defaultTeamId) {
                $plainPrompt .= " @{$this->defaultTeamId}";
            }
            $plainPrompt .= " @{$this->defaultAgentId}> ";

            // Read input
            if ($readlineAvailable) {
                $input = readline($plainPrompt);
            } else {
                $this->output->write($prompt);
                $input = fgets(STDIN);
            }

            // Handle EOF (Ctrl+D)
            if ($input === false) {
                $this->newLine();
                $this->line('<fg=gray>Goodbye! 👋</>');
                $this->historyManager->save();

                return Command::SUCCESS;
            }

            $input = trim($input);

            if ($input === '') {
                continue;
            }

            // Add to history
            $this->historyManager->add($input);

            // Handle shell commands
            if (str_starts_with($input, '/')) {
                $result = $this->commandHandler->handle(
                    $input,
                    $this->output,
                    $this->defaultAgentId,
                    $this->defaultTeamId,
                    $this->shouldReset,
                    $this->currentSession,
                    $this->senderId,
                    $this->historyManager,
                );

                if ($result === 'exit') {
                    $this->historyManager->save();

                    return Command::SUCCESS;
                }

                continue;
            }

            // Process message through agent
            $this->messageProcessor->processMessage(
                $input,
                $this->output,
                $this->defaultAgentId,
                $this->defaultTeamId,
                $this->shouldReset,
            );
        }
    }
}

<?php

namespace App\Console\Commands;

use App\DTOs\CommandResponseDTO;
use App\DTOs\RoutingResultDTO;
use App\Models\Event;
use App\Services\CommandProcessingService;
use App\Services\MemoryEngineService;
use App\Services\RoutingService;
use Illuminate\Console\Command;

use function Safe\json_encode;

class LaraClawCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'laraclaw:command
                            {message* : The message to send to the agent}
                            {--agent= : Specific agent ID to route to}
                            {--team= : Specific team ID to route to}
                            {--reset : Reset conversation context}
                            {--json : Output response as JSON}';

    /**
     * The console command description.
     */
    protected $description = 'Send a command directly to an agent from CLI';

    protected CommandProcessingService $commandService;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Initialize the command processing service with memory service
        $this->commandService = app(CommandProcessingService::class);
        $this->commandService->setMemoryService(app(MemoryEngineService::class));

        // Set up routing service for intelligent routing
        $routingService = app(RoutingService::class);
        $this->commandService->setRoutingService($routingService);

        // Get message from arguments
        $messageParts = $this->argument('message');
        if (empty($messageParts)) {
            $this->error('Please provide a message.');
            $this->info('Usage: php artisan laraclaw:command "your message here"');
            $this->info('       php artisan laraclaw:command @agent_id "your message"');

            return Command::FAILURE;
        }

        $message = implode(' ', $messageParts);
        $outputAsJson = (bool) $this->option('json');

        // Build options for the command service
        $options = [
            'agent' => $this->option('agent'),
            'team' => $this->option('team'),
            'reset' => $this->option('reset'),
        ];

        // Emit event
        Event::emit('message_received', [
            'channel' => 'cli',
            'sender' => 'user',
            'message' => $message,
        ]);

        // Process with intelligent routing
        $startTime = microtime(true);
        $result = $this->commandService->processWithRouting($message, $options);
        $duration = round(microtime(true) - $startTime, 2);

        $response = $result['response'];
        $routing = $result['routing'];

        // Handle error responses
        if (! $response->success) {
            if ($outputAsJson) {
                $this->line(json_encode(
                    [
                        'error' => true,
                        'code' => $response->code,
                    ], JSON_PRETTY_PRINT));
            } else {
                $this->error($response->message);
            }

            return Command::FAILURE;
        }

        // Get conversation ID from the response data (saved by CommandProcessingService)
        $conversationId = $response->data['conversation_id'] ?? null;
        $agentId = $response->data['agent_id'] ?? 'unknown';

        // Emit completion event
        Event::emit('response_ready', [
            'channel' => 'cli',
            'agentId' => $agentId,
            'sender' => 'user',
            'responseLength' => strlen($response->message),
        ]);

        // Output response
        if ($outputAsJson) {
            $this->line(json_encode(array_merge(
                $response->data,
                [
                    'duration_seconds' => $duration,
                    'response' => $response->message,
                ]
            ), JSON_PRETTY_PRINT));
        } else {
            $this->displayCliOutput($response, $routing, $duration);
        }

        return Command::SUCCESS;
    }

    /**
     * Display the response in CLI format.
     */
    protected function displayCliOutput(CommandResponseDTO $response, ?RoutingResultDTO $routing, float $duration): void
    {
        $data = $response->data;

        $this->newLine();
        $this->line('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');

        // Agent info
        $agentId = $data['agent_id'] ?? 'unknown';
        $agentName = $data['agent_name'] ?? $agentId;
        $this->line("<fg=white;options=bold>  Agent:</>     <fg=green>@{$agentId}</> ({$agentName})");

        // Model info
        $provider = $data['provider'] ?? 'unknown';
        $model = $data['model'] ?? 'unknown';
        $this->line("<fg=white;options=bold>  Model:</>     <fg=yellow>{$provider}/{$model}</>");

        // Team info if applicable
        $teamId = $data['team_id'] ?? null;
        $isTeamRouted = $data['is_team_routed'] ?? false;
        if ($isTeamRouted && $teamId) {
            $teams = $this->commandService->getSettings()->getTeams();
            $teamName = $teams[$teamId]->name ?? $teamId;
            $this->line("<fg=white;options=bold>  Team:</>      <fg=magenta>@{$teamId}</> ({$teamName})");
        }

        // Routing method and intent if available
        $routingMethod = $data['routing_method'] ?? null;
        if ($routingMethod) {
            $this->line("<fg=white;options=bold>  Routing:</>   <fg=blue>{$routingMethod}</>");
        }

        $intent = $data['intent'] ?? null;
        if ($intent) {
            $confidence = isset($data['intent_confidence'])
                ? round($data['intent_confidence'] * 100)
                : 0;
            $this->line("<fg=white;options=bold>  Intent:</>    <fg=gray>{$intent}</> <fg=gray>({$confidence}% confidence)</>");
        }

        $suggestedSkills = $data['suggested_skills'] ?? [];
        if (! empty($suggestedSkills)) {
            $skills = implode(', ', $suggestedSkills);
            $this->line("<fg=white;options=bold>  Skills:</>    <fg=gray>{$skills}</>");
        }

        $this->line('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->newLine();

        // Clear the "Processing..." line and show response
        $this->output->write("\r\033[K");
        $this->line('<fg=green>  Response:</>');
        $this->newLine();

        // Word wrap the response for better readability
        $wrapped = wordwrap($response->message, 70);
        foreach (explode("\n", $wrapped) as $line) {
            $this->line("  <fg=white>{$line}</>");
        }

        $this->newLine();
        $this->line('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->line("<fg=gray>  Completed in {$duration}s</>");
        $this->newLine();
    }
}

<?php

namespace App\Console\Commands;

use App\DTOs\SkillDiscoveryResultDTO;
use App\Services\SkillAutoDiscoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;
use function Safe\preg_match;
use function Safe\preg_replace;
use function Safe\preg_split;

/**
 * Interactive skill search and install command.
 *
 * Provides a shell-like interface for searching and installing skills
 * from the skills.sh registry using `npx skills find` and `npx skills add`.
 */
class LaraClawSkillInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'laraclaw:skill:install
                            {search? : Search term to find skills}
                            {--max=10 : Maximum number of results to show}';

    /**
     * The console command description.
     */
    protected $description = 'Interactive skill search and install from skills.sh registry';

    protected SkillAutoDiscoveryService $discoveryService;

    /**
     * Execute the console command.
     */
    public function handle(SkillAutoDiscoveryService $discoveryService): int
    {
        $this->discoveryService = $discoveryService;

        $this->displayHeader();

        // Get initial search term from argument or prompt
        $searchTerm = $this->argument('search');

        if (empty($searchTerm)) {
            $searchTerm = $this->promptForSearchTerm();
        }

        // Main interactive loop
        while (true) {
            if ($searchTerm === null) {
                // User pressed Ctrl-D on prompt
                $this->line('');
                info('Goodbye!');

                return Command::SUCCESS;
            }

            // Search for skills
            $this->line('');
            $this->line("<fg=cyan>Searching for:</> <comment>{$searchTerm}</comment>...");

            $result = $this->searchSkills($searchTerm);

            if ($result === null || ! $result->hasMatches()) {
                warning("No skills found for \"{$searchTerm}\"");
            } else {
                // Display results
                $this->displaySearchResults($result);

                // Prompt for selection
                $selection = $this->promptForSelection($result);

                if ($selection === 'exit') {
                    $this->line('');
                    info('Goodbye!');

                    return Command::SUCCESS;
                }

                if ($selection === 'new_search') {
                    $searchTerm = $this->promptForSearchTerm();

                    continue;
                }

                // Install selected skill
                if (is_int($selection)) {
                    $this->installSkill($result, $selection);
                }
            }

            // Prompt for next search
            $this->line('');
            $searchTerm = $this->promptForSearchTerm();
        }
    }

    /**
     * Display the command header.
     */
    protected function displayHeader(): void
    {
        $this->newLine();
        $this->line('<fg=cyan>  '.str_repeat('─', 60).'</>');
        $this->line('<fg=green>    LaraClaw Skill Installer</>');
        $this->line('<fg=cyan>  '.str_repeat('─', 60).'</>');
        $this->newLine();
        $this->line('<fg=gray>  Search and install skills from the skills.sh registry.</>');
        $this->line('<fg=gray>  Press Ctrl-D to exit at any time.</>');
        $this->newLine();
    }

    /**
     * Prompt user for a search term.
     * Returns null if Ctrl-D was pressed.
     */
    protected function promptForSearchTerm(): ?string
    {
        try {
            return text(
                label: 'Enter search term (or press Ctrl-D to exit)',
                placeholder: 'e.g., image generation, web browsing, calendar',
                hint: 'Search for skills by name, category, or functionality'
            );
        } catch (\RuntimeException $e) {
            // Ctrl-D was pressed
            return null;
        }
    }

    /**
     * Search for skills using the discovery service.
     */
    protected function searchSkills(string $searchTerm): ?SkillDiscoveryResultDTO
    {
        $maxResults = (int) $this->option('max');

        // Clear cache to get fresh results
        $cacheKey = 'skills_find:'.md5($searchTerm);
        Cache::forget($cacheKey);

        // Run the search
        $matches = $this->runSkillsFind($searchTerm);

        if (empty($matches)) {
            return null;
        }

        return new SkillDiscoveryResultDTO(
            searchTerm: $searchTerm,
            matches: array_slice($matches, 0, $maxResults),
            autoInstallEnabled: false,
            autoInstallMode: 'prompt'
        );
    }

    /**
     * Run `npx skills find` and parse the output.
     *
     * @return list<array{
     *      name: string,
     *      description: string,
     *      owner: string,
     *      repo: string,
     *      installs: int
     *  }>
     */
    protected function runSkillsFind(string $searchTerm): array
    {
        $result = Process::timeout(60)->run(['npx', 'skills', 'find', $searchTerm]);

        if (! $result->successful()) {
            warning('Failed to search for skills: '.$result->errorOutput());

            return [];
        }

        $output = trim($result->output());

        if (empty($output)) {
            return [];
        }

        return $this->parseSkillsFindOutput($output);
    }

    /**
     * Parse the text output from `npx skills find`.
     *
     * @return list<array{
     *     name: string,
     *     description: string,
     *     owner: string,
     *     repo: string,
     *     installs: int
     * }>
     */
    protected function parseSkillsFindOutput(string $output): array
    {
        $matches = [];

        // Strip ANSI color codes
        $output = preg_replace('/\x1b\[[0-9;]*m/', '', $output);

        $lines = preg_split('/\r?\n/', $output);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and header/banner lines
            if (empty($line) || str_contains($line, '████') || str_contains($line, 'Install with')) {
                continue;
            }

            // Match skill line: owner/repo@skill    X.XK installs
            // The line may have ANSI codes or other characters before the pattern
            if (preg_match('/([a-zA-Z0-9_-]+)\/([a-zA-Z0-9_-]+)@([a-zA-Z0-9_-]+)/', $line, $skillMatch)) {
                $owner = $skillMatch[1];
                $repo = $skillMatch[2];
                $skillName = $skillMatch[3];

                // Extract installs count if present
                $installs = 0;
                if (preg_match('/([\d.]+[KM]?)\s*installs/i', $line, $installsMatch)) {
                    $installs = $this->parseInstallsCount($installsMatch[1]);
                }

                $matches[] = [
                    'name' => $skillName,
                    'description' => "{$owner}/{$repo}",
                    'owner' => $owner,
                    'repo' => $repo,
                    'installs' => $installs,
                ];
            }
        }

        return $matches;
    }

    /**
     * Parse installs count like "4.6K" or "1.2M" to integer.
     */
    protected function parseInstallsCount(string $count): int
    {
        $count = strtoupper(trim($count));

        if (str_ends_with($count, 'K')) {
            return (int) ((float) $count * 1000);
        }

        if (str_ends_with($count, 'M')) {
            return (int) ((float) $count * 1000000);
        }

        return (int) $count;
    }

    /**
     * Display search results in a formatted list.
     */
    protected function displaySearchResults(SkillDiscoveryResultDTO $result): void
    {
        $this->line('');
        $this->line('<fg=green>Found '.count($result->matches)." skill(s) for \"{$result->searchTerm}\":</>");
        $this->line('');

        foreach ($result->matches as $i => $match) {
            $num = $i + 1;
            $installs = $match['installs'] ?? 0;
            $installsStr = $installs > 0 ? " <fg=yellow>({$installs} installs)</>" : '';

            $this->line("  <fg=cyan>{$num}.</> <comment>{$match['name']}</comment>{$installsStr}");
            $this->line("     <fg=gray>{$match['description']}</>");
        }

        $this->line('');
    }

    /**
     * Prompt user to select a skill or action.
     * Returns: int (skill index), 'new_search', or 'exit'
     */
    protected function promptForSelection(SkillDiscoveryResultDTO $result): int|string
    {
        $options = [];

        // Add skill options
        foreach ($result->matches as $i => $match) {
            $options[$i] = "Install: {$match['name']}";
        }

        // Add action options
        $options['new_search'] = 'Search for different skills...';
        $options['exit'] = 'Exit';

        try {
            $selection = select(
                label: 'Select a skill to install',
                options: $options,
                scroll: 15
            );

            return $selection;
        } catch (\RuntimeException $e) {
            // Ctrl-D was pressed
            return 'exit';
        }
    }

    /**
     * Install a skill by index.
     */
    protected function installSkill(SkillDiscoveryResultDTO $result, int $index): void
    {
        $match = $result->matches[$index] ?? null;

        if (! $match) {
            error("Invalid skill selection: {$index}");

            return;
        }

        $installCmd = $result->getInstallCommand($index);

        $this->line('');
        $this->line("<fg=cyan>Installing:</> <comment>{$match['name']}</comment> ({$installCmd})");
        $this->line('');

        // Pass a callback to show real-time output
        $success = $this->discoveryService->installSkill($installCmd, function (string $type, string $output): void {
            // Strip ANSI codes for cleaner display
            $output = preg_replace('/\x1b\[[0-9;]*m/', '', $output);
            $output = trim($output);

            if (! empty($output)) {
                // 'out' is stdout, 'err' is stderr
                if ($type === 'err') {
                    $this->line("<fg=yellow>{$output}</>");
                } else {
                    $this->line("  <fg=gray>{$output}</>");
                }
            }
        });

        if ($success) {
            $this->line('');
            info("✓ Skill installed successfully: {$match['name']}");

            // Refresh the skill index
            $this->line('<fg=gray>Refreshing skill index...</>');
            $this->discoveryService->refreshSkillIndex();
            info('✓ Skill index refreshed');
        } else {
            $this->line('');
            error("✗ Failed to install skill: {$match['name']}");
        }
    }
}

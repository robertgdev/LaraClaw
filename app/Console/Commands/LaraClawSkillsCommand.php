<?php

namespace App\Console\Commands;

use App\Models\Skill;
use App\Services\SkillClassificationService;
use Illuminate\Console\Command;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;
use function Safe\json_encode;

class LaraClawSkillsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'laraclaw:skills
                            {--force : Clear existing mappings before classification}
                            {--force-skill=* : Force re-classification of specific skills}
                            {--json : Output as JSON}
                            {--stats : Show cache statistics only}
                            {--dry-run : Show what would be classified without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Classify skills and populate intent cache for faster skill matching';

    /**
     * Execute the console command.
     */
    public function handle(SkillClassificationService $service): int
    {
        // Show stats only mode
        if ($this->option('stats')) {
            return $this->showStatistics($service);
        }

        // Dry run mode
        if ($this->option('dry-run')) {
            return $this->showDryRun($service);
        }

        $this->displayHeader();

        $clearExisting = $this->option('force');
        $forceSkills = $this->option('force-skill');

        // Warn if force mode
        if ($clearExisting) {
            warning('Force mode: All existing skill mappings will be cleared!');
            $this->newLine();

            if (! confirm('Continue with force mode?', true)) {
                info('Operation cancelled.');

                return Command::SUCCESS;
            }
        }

        // Handle force-skill option
        if (! empty($forceSkills)) {
            $this->line('  <fg=yellow>Force re-classifying specific skills:</>');
            foreach ($forceSkills as $skillName) {
                $this->line("    - {$skillName}");
            }
            $this->newLine();

            // Reset classification status for specified skills
            foreach ($forceSkills as $skillName) {
                Skill::where('name', $skillName)->update(['classification_status' => Skill::STATUS_PENDING]);
            }
        }

        // Show what we're about to do
        $this->newLine();
        $this->line('  <info>This will:</info>');
        $this->line('    1. Index all available skills from filesystem');
        $this->line('    2. Sync skills to database (detecting changes via checksum)');
        $this->line('    3. Send skill metadata to the LLM for classification');
        $this->line('    4. Generate sample intents for each skill');
        $this->line('    5. Store mappings in the skill_match cache table');
        $this->newLine();

        warning('Note: This will consume LLM tokens for skills that have changed.');
        $this->newLine();

        if (! confirm('Proceed with skill classification?', true)) {
            info('Operation cancelled.');

            return Command::SUCCESS;
        }

        // Run classification
        $this->line(' Note: Unchanged skills will be skipped and will not be reclassified.');

        try {
            // Track progress for display
            $progressCallback = function (string $skillName, int $mappingsCount, int $total, int $current, string $status) {
                $statusIcon = match ($status) {
                    'classified' => '<fg=green>✓</>',
                    'failed' => '<fg=red>✗</>',
                    'skipped' => '<fg=yellow>⊘</>',
                    default => '<fg=cyan>?</>',
                };

                $statusText = match ($status) {
                    'classified' => "{$mappingsCount} intents",
                    'failed' => 'failed',
                    'skipped' => 'skipped (unchanged)',
                    default => $status,
                };

                $this->line("  <fg=cyan>[{$current}/{$total}]</> <comment>{$skillName}</comment> - {$statusIcon} {$statusText}");
            };

            $result = $service->classifyAllSkills($clearExisting, $progressCallback);

            // Output results
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT));
            } else {
                $this->displayResults($result);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->newLine();
            $this->error('Classification failed: '.$e->getMessage());

            if ($this->output->isVerbose()) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Display the command header.
     */
    protected function displayHeader(): void
    {
        $this->newLine();
        $this->line('<fg=cyan>  '.str_repeat('=', 60).'</>');
        $this->line('<fg=green>    LaraClaw Skill Classification</>');
        $this->line('<fg=cyan>  '.str_repeat('=', 60).'</>');
        $this->newLine();
    }

    /**
     * Display the classification results.
     */
    protected function displayResults(array $result): void
    {
        $this->newLine();
        $this->line('<fg=green>  ┌──────────────────────────────────────────────────────────┐</>');
        $this->line('<fg=green>  │</> <fg=white;options=bold>  Classification Complete!</>                               <fg=green>│</>');
        $this->line('<fg=green>  │</>                                                          <fg=green>│</>');
        $this->line("<fg=green>  │</>   <fg=cyan>Skills processed:</>    {$result['skills_processed']}                                 <fg=green>│</>");
        $this->line("<fg=green>  │</>   <fg=cyan>Skills skipped:</>      {$result['skills_skipped']}                                 <fg=green>│</>");
        $this->line("<fg=green>  │</>   <fg=cyan>Mappings generated:</>  {$result['mappings_generated']}                                 <fg=green>│</>");
        $this->line("<fg=green>  │</>   <fg=cyan>Mappings stored:</>     {$result['mappings_stored']}                                 <fg=green>│</>");
        $this->line('<fg=green>  │</>                                                          <fg=green>│</>');
        $this->line('<fg=green>  └──────────────────────────────────────────────────────────┘</>');
        $this->newLine();

        if (! empty($result['errors'])) {
            warning('Errors occurred:');
            foreach ($result['errors'] as $error) {
                $this->line("  - {$error}");
            }
            $this->newLine();
        }

        info('The skill match cache is now populated and ready for use.');
        $this->line('  Run <info>php artisan laraclaw:skill --stats</info> to view cache statistics.');
    }

    /**
     * Show cache statistics.
     */
    protected function showStatistics(SkillClassificationService $service): int
    {
        $stats = $service->getCacheStatistics();

        if ($this->option('json')) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan>  '.str_repeat('=', 60).'</>');
        $this->line('<fg=green>    Skill Match Cache Statistics</>');
        $this->line('<fg=cyan>  '.str_repeat('=', 60).'</>');
        $this->newLine();

        $this->line("  <info>Total entries:</info>       {$stats['total_entries']}");
        $this->line("  <info>Total cache hits:</info>   {$stats['total_hits']}");
        $this->line("  <info>Skills covered:</info>     {$stats['skills_covered']}");
        $this->newLine();

        $this->line('<fg=cyan>  Skill Classification Status:</>');
        $this->line("    <fg=green>✓</> Classified:  {$stats['skills_classified']}");
        $this->line("    <fg=yellow>○</> Pending:     {$stats['skills_pending']}");
        $this->line("    <fg=red>✗</> Failed:      {$stats['skills_failed']}");
        $this->newLine();

        return Command::SUCCESS;
    }

    /**
     * Show dry run - what would be classified.
     */
    protected function showDryRun(SkillClassificationService $service): int
    {
        $this->newLine();
        $this->line('<fg=cyan>  '.str_repeat('=', 60).'</>');
        $this->line('<fg=yellow>    Dry Run - Skills Analysis</>');
        $this->line('<fg=cyan>  '.str_repeat('=', 60).'</>');
        $this->newLine();

        // Index skills and sync to get current state
        $indexedSkills = $service->classifyAllSkills(false, null);

        // Get skills that would need classification
        $pendingSkills = Skill::active()
            ->needsClassification()
            ->get(['name', 'classification_status', 'checksum', 'updated_at']);

        $classifiedSkills = Skill::active()
            ->where('classification_status', Skill::STATUS_CLASSIFIED)
            ->get(['name', 'classification_status', 'checksum', 'classified_at', 'intents_count']);

        $this->line('  <info>Total skills found:</info>     '.Skill::active()->count());
        $this->line('  <fg=yellow>Skills needing classification:</> '.$pendingSkills->count());
        $this->line('  <fg=green>Skills already classified:</>    '.$classifiedSkills->count());
        $this->newLine();

        if ($pendingSkills->isNotEmpty()) {
            $this->line('<fg=yellow>  Skills that would be classified:</>');
            foreach ($pendingSkills as $skill) {
                $status = $skill->classification_status === Skill::STATUS_FAILED
                    ? '<fg=red>(failed)</>'
                    : "<fg=cyan>({$skill->classification_status})</>";
                $this->line("    - {$skill->name} {$status}");
            }
            $this->newLine();
        }

        if ($classifiedSkills->isNotEmpty()) {
            $this->line('<fg=green>  Skills that would be skipped (unchanged):</>');
            foreach ($classifiedSkills as $skill) {
                $date = $skill->classified_at?->diffForHumans() ?? 'never';
                $this->line("    - {$skill->name} <fg=cyan>({$skill->intents_count} intents, {$date})</>");
            }
            $this->newLine();
        }

        $this->line('  <info>Run without --dry-run to perform actual classification.</info>');
        $this->newLine();

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Shell;

use Illuminate\Support\Facades\File;

use function Safe\readline_read_history;

/**
 * Manages shell command history with readline integration.
 *
 * Handles loading, saving, and adding to command history.
 * Supports readline history API when available.
 */
class ShellHistoryManager
{
    /** @var array<int, string> */
    protected array $history = [];

    protected int $historyIndex = 0;

    protected int $maxHistory;

    protected string $historyFile;

    public function __construct(string $historyFile, int $maxHistory = 1000)
    {
        $this->historyFile = $historyFile;
        $this->maxHistory = $maxHistory;
    }

    /**
     * Load command history from file.
     */
    public function load(): void
    {
        if (File::exists($this->historyFile)) {
            $this->history = array_filter(
                explode("\n", File::get($this->historyFile)),
                fn ($line) => trim($line) !== ''
            );
            $this->historyIndex = count($this->history);

            // Load into readline if available
            if (function_exists('readline_read_history')) {
                try {
                    readline_read_history($this->historyFile);
                } catch (\Throwable $e) {
                    // Ignore readline errors - not critical for functionality
                }
            }
        }
    }

    /**
     * Save command history to file.
     */
    public function save(): void
    {
        // Ensure directory exists
        $dir = dirname($this->historyFile);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        // Keep only the last N entries
        $history = array_slice($this->history, -$this->maxHistory);
        File::put($this->historyFile, implode("\n", $history)."\n");
    }

    /**
     * Add a command to history.
     */
    public function add(string $input): void
    {
        // Don't add duplicates in sequence
        if (end($this->history) === $input) {
            return;
        }

        $this->history[] = $input;
        $this->historyIndex = count($this->history);

        // Add to readline if available
        if (function_exists('readline_add_history')) {
            readline_add_history($input);
        }
    }

    /**
     * Get all history entries.
     *
     * @return array<int, string>
     */
    public function getAll(): array
    {
        return $this->history;
    }

    /**
     * Get recent history entries.
     *
     * @param  int  $count  Number of recent entries to return
     * @return array<int, string>
     */
    public function getRecent(int $count = 20): array
    {
        $start = max(0, count($this->history) - $count);

        return array_slice($this->history, $start);
    }

    /**
     * Check if history is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->history);
    }
}

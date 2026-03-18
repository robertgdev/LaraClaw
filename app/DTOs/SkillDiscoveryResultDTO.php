<?php

namespace App\DTOs;

/**
 * Data Transfer Object for skill discovery results.
 *
 * Represents the result of searching for skills via `npx skills find`.
 * Used to communicate discovery state between services and to format
 * user-facing messages.
 */
class SkillDiscoveryResultDTO
{
    /**
     * Whether the skill was auto-installed without user interaction.
     */
    public bool $wasAutoInstalled = false;

    /**
     * The skill that was installed (if any).
     */
    public ?string $installedSkillName = null;

    /**
     * @param  string  $searchTerm  The search term used
     * @param  array<array{name: string, description: string, owner: string, repo: string, version?: string, installs?: int}>  $matches
     * @param  bool  $autoInstallEnabled  Whether auto-install is enabled in config
     * @param  string  $autoInstallMode  'first' or 'prompt'
     */
    public function __construct(
        public string $searchTerm,
        public array $matches,
        public bool $autoInstallEnabled = false,
        public string $autoInstallMode = 'prompt', // FIXME: convert to enum
    ) {}

    /**
     * Get the top match (first in list, sorted by popularity).
     *
     * @return null|array{
     *     name: string,
     *     description: string,
     *     owner: string,
     *     repo: string,
     *     version?: string,
     *     installs?: int}  $matches
     */
    public function getTopMatch(): ?array
    {
        return $this->matches[0] ?? null;
    }

    /**
     * Get the install command for a specific match index.
     *
     * @param  int  $index  Index in matches array (0-based)
     * @return string|null Install command like "owner/repo@skillname"
     */
    public function getInstallCommand(int $index = 0): ?string
    {
        $match = $this->matches[$index] ?? null;
        if (! $match) {
            return null;
        }

        return sprintf('%s/%s@%s', $match['owner'], $match['repo'], $match['name']);
    }

    /**
     * Mark this result as auto-installed.
     */
    public function markAsAutoInstalled(string $skillName): self
    {
        $this->wasAutoInstalled = true;
        $this->installedSkillName = $skillName;

        return $this;
    }

    /**
     * Check if user needs to make a choice.
     * True when not auto-installed and there are matches to choose from.
     */
    public function needsUserChoice(): bool
    {
        return ! $this->wasAutoInstalled && count($this->matches) > 0;
    }

    /**
     * Alias for needsUserChoice() for clarity.
     */
    public function needsUserSelection(): bool
    {
        return $this->needsUserChoice();
    }

    /**
     * Check if there are any matches.
     */
    public function hasMatches(): bool
    {
        return count($this->matches) > 0;
    }

    /**
     * Check if this result should trigger auto-install of first match.
     */
    public function shouldAutoInstallFirst(): bool
    {
        return $this->autoInstallEnabled
            && $this->autoInstallMode === 'first'
            && count($this->matches) > 0;
    }

    /**
     * Check if this result should auto-install (single match case).
     */
    public function shouldAutoInstallSingle(): bool
    {
        return $this->autoInstallEnabled && count($this->matches) === 1;
    }

    /**
     * Format message for user to choose from multiple skills.
     */
    public function formatPromptMessage(): string
    {
        $lines = [
            'I found '.count($this->matches)." skill(s) for \"{$this->searchTerm}\":",
            '',
        ];

        foreach ($this->matches as $i => $match) {
            $num = $i + 1;
            $installs = $match['installs'] ?? 0;
            $installsStr = $installs > 0 ? " ({$installs} installs)" : '';
            $lines[] = "{$num}. **{$match['name']}**{$installsStr}";
            $lines[] = "   {$match['description']}";
        }

        $lines[] = '';
        $lines[] = 'Reply with a number (1-'.count($this->matches).") to install, or 'skip' to continue without.";

        return implode("\n", $lines);
    }

    /**
     * Format confirmation message after auto-install.
     */
    public function formatAutoInstalledMessage(): string
    {
        $match = $this->getTopMatch();
        $name = $match['name'] ?? $this->installedSkillName ?? 'skill';

        return "Auto-installed skill: **{$name}**\n\nNow processing your request...";
    }

    /**
     * Format error message when no matches found.
     */
    public static function formatNoMatchMessage(string $searchTerm): string
    {
        return "I searched for \"{$searchTerm}\" but didn't find any matching skills. I'll try to help with your request anyway.";
    }

    /**
     * Convert to array for serialization (e.g., cache storage).
     *
     * @return array{
     *     searchTerm: string,
     *     matches: array<array{
     *          name: string,
     *          description: string,
     *          owner: string,
     *          repo: string,
     *          version?: string,
     *          installs?: int
     *      }>,
     *     autoInstallEnabled: bool,
     *     autoInstallMode: string,
     *     wasAutoInstalled: bool,
     *     installedSkillName: string
     * }
     */
    public function toArray(): array
    {
        return [
            'searchTerm' => $this->searchTerm,
            'matches' => $this->matches,
            'autoInstallEnabled' => $this->autoInstallEnabled,
            'autoInstallMode' => $this->autoInstallMode,
            'wasAutoInstalled' => $this->wasAutoInstalled,
            'installedSkillName' => $this->installedSkillName,
        ];
    }

    /**
     * Create from array (e.g., from cache).
     *
     * @param array{
     *     searchTerm: string,
     *     matches: array<array{name: string, description: string, owner: string, repo: string, version?: string, installs?: int}>,
     *     autoInstallEnabled: bool,
     *     autoInstallMode: string,
     *     wasAutoInstalled: bool,
     *     installedSkillName: string
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $dto = new self(
            searchTerm: $data['searchTerm'],
            matches: $data['matches'],
            autoInstallEnabled: $data['autoInstallEnabled'] ?? false,
            autoInstallMode: $data['autoInstallMode'] ?? 'prompt',
        );

        if ($data['wasAutoInstalled'] ?? false) {
            $dto->wasAutoInstalled = true;
            $dto->installedSkillName = $data['installedSkillName'] ?? null;
        }

        return $dto;
    }
}

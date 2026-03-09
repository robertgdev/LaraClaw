<?php

declare(strict_types=1);

namespace App\Services\Discovery;

/**
 * Parses the text output from `npx skills find`.
 *
 * Output format:
 *   owner/repo@skill    X.XK installs
 *   └ https://skills.sh/owner/repo/skill
 */
class SkillsFindOutputParser
{
    /**
     * Parse the text output from `npx skills find`.
     *
     * @param  string  $output  The raw output
     * @return array<array{name: string, description: string, owner: string, repo: string, installs?: int}>
     */
    public function parse(string $output): array
    {
        $matches = [];
        $lines = preg_split('/\r?\n/', $output);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and header/banner lines
            if (empty($line) || str_contains($line, '████') || str_contains($line, 'Install with')) {
                continue;
            }

            // Match skill line: owner/repo@skill    X.XK installs
            if (preg_match('/^([a-zA-Z0-9_-]+)\/([a-zA-Z0-9_-]+)@([a-zA-Z0-9_-]+)/', $line, $skillMatch)) {
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
                    'description' => '',
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
    public function parseInstallsCount(string $count): int
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
}

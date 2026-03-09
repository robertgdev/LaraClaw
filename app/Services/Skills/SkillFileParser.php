<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\Services\KeywordExtractor;
use Illuminate\Support\Facades\File;

use function Safe\preg_match;
use function Safe\preg_replace;

/**
 * Parses SKILL.md files and extracts structured metadata.
 *
 * Handles YAML frontmatter extraction, keyword generation from description
 * and body, and detection of subdirectories (scripts, references, assets).
 */
class SkillFileParser
{
    /**
     * Parse a SKILL.md file and extract metadata.
     *
     * @param  string  $path  Full path to the SKILL.md file
     * @return array|null Parsed skill data or null if invalid
     */
    public function parse(string $path): ?array
    {
        $content = File::get($path);

        // Extract YAML frontmatter
        if (! preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches)) {
            return null;
        }

        $frontmatter = $matches[1];
        $body = $matches[2];

        // Parse YAML frontmatter (simple parsing)
        $metadata = [];
        $lines = explode("\n", $frontmatter);

        foreach ($lines as $line) {
            if (preg_match('/^(\w+):\s*(.*)$/', $line, $match)) {
                $key = trim($match[1]);
                $value = trim($match[2]);
                // Remove surrounding quotes from value
                $value = preg_replace('/^["\'](.*)["\']$/', '$1', $value);
                $metadata[$key] = $value;
            }
        }

        if (! isset($metadata['name']) || ! isset($metadata['description'])) {
            return null;
        }

        // Extract keywords from description and body
        $keywords = KeywordExtractor::extract($metadata['description'].' '.$body, 20);

        // Get skill directory name
        $dirName = basename(dirname($path));

        return [
            'name' => $metadata['name'],
            'dir_name' => $dirName,
            'description' => $metadata['description'],
            'license' => $metadata['license'] ?? null,
            'path' => $path,
            'directory' => dirname($path),
            'keywords' => $keywords,
            'has_scripts' => File::isDirectory(dirname($path).'/scripts'),
            'has_references' => File::isDirectory(dirname($path).'/references'),
            'has_assets' => File::isDirectory(dirname($path).'/assets'),
        ];
    }
}

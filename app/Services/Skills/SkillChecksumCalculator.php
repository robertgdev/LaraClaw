<?php

declare(strict_types=1);

namespace App\Services\Skills;

use Illuminate\Support\Facades\File;

/**
 * Calculates checksums for skill directories to detect changes.
 *
 * Checksums are calculated from ALL files in the skill directory recursively.
 * This ensures that any change to SKILL.md, scripts, references, or assets
 * triggers a re-classification.
 */
class SkillChecksumCalculator
{
    /**
     * Calculate checksum for a skill directory.
     * Includes ALL files recursively for comprehensive change detection.
     * Uses file metadata (name, mtime, size) for fast hashing.
     */
    public function calculate(string $directory): string
    {
        if (! File::isDirectory($directory)) {
            return '';
        }

        $hashContext = hash_init('sha256');

        // Get all files recursively, sorted for consistent ordering
        $files = collect(File::allFiles($directory))
            ->sortBy(fn ($file) => $file->getRelativePathname())
            ->values();

        foreach ($files as $file) {
            // Include file path in hash (detects renames)
            hash_update($hashContext, $file->getRelativePathname());

            // Include file modification time (detects content changes efficiently)
            hash_update($hashContext, (string) $file->getMTime());

            // Include file size (quick change detection)
            hash_update($hashContext, (string) $file->getSize());
        }

        return hash_final($hashContext);
    }

    /**
     * Calculate checksum including file contents (more thorough but slower).
     * Use this for critical change detection.
     */
    public function calculateThorough(string $directory): string
    {
        if (! File::isDirectory($directory)) {
            return '';
        }

        $hashContext = hash_init('sha256');

        // Get all files recursively, sorted for consistent ordering
        $files = collect(File::allFiles($directory))
            ->sortBy(fn ($file) => $file->getRelativePathname())
            ->values();

        foreach ($files as $file) {
            // Include file path in hash (detects renames)
            hash_update($hashContext, $file->getRelativePathname());

            // Include full file content (detects any content change)
            hash_update($hashContext, File::get($file->getPathname()));
        }

        return hash_final($hashContext);
    }
}

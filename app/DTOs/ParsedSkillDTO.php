<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents a parsed skill from a SKILL.md file.
 *
 * Used by SkillFileParser::parse() to return structured skill data
 * extracted from the SKILL.md file.
 */
final readonly class ParsedSkillDTO
{
    /**
     * @param string $name Skill name from frontmatter
     * @param string $dirName Directory name (basename of parent directory)
     * @param string $description Skill description from frontmatter
     * @param string $path Full path to SKILL.md file
     * @param string $directory Full path to skill directory
     * @param array<string> $keywords Extracted keywords
     * @param bool $hasScripts Has scripts subdirectory
     * @param bool $hasReferences Has references subdirectory
     * @param bool $hasAssets Has assets subdirectory
     * @param string|null $license License from frontmatter (optional)
     */
    public function __construct(
        public string $name,
        public string $dirName,
        public string $description,
        public string $path,
        public string $directory,
        public array $keywords,
        public bool $hasScripts,
        public bool $hasReferences,
        public bool $hasAssets,
        public ?string $license = null,
    ) {}

    /**
     * Check if this skill has any additional resources.
     */
    public function hasResources(): bool
    {
        return $this->hasScripts || $this->hasReferences || $this->hasAssets;
    }

    /**
     * Convert to SkillDTO.
     */
    public function toSkillDTO(string $sourceType = 'unknown'): SkillDTO
    {
        return new SkillDTO(
            name: $this->name,
            dirName: $this->dirName,
            description: $this->description,
            path: $this->path,
            directory: $this->directory,
            keywords: $this->keywords,
            hasScripts: $this->hasScripts,
            hasReferences: $this->hasReferences,
            hasAssets: $this->hasAssets,
            license: $this->license,
            sourceType: $sourceType,
        );
    }

    /**
     * Create from an array (for backward compatibility).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $path = $data['path'] ?? ($data['directory'] ?? '/unknown/path').'/SKILL.md';
        $directory = $data['directory'] ?? (isset($data['path']) ? dirname($data['path']) : '/unknown/path');

        return new self(
            name: $data['name'],
            dirName: $data['dir_name'] ?? basename($directory),
            description: $data['description'] ?? '',
            path: $path,
            directory: $directory,
            keywords: $data['keywords'] ?? [],
            hasScripts: $data['has_scripts'] ?? false,
            hasReferences: $data['has_references'] ?? false,
            hasAssets: $data['has_assets'] ?? false,
            license: $data['license'] ?? null,
        );
    }

    /**
     * Convert to array (for backward compatibility).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'dir_name' => $this->dirName,
            'description' => $this->description,
            'path' => $this->path,
            'directory' => $this->directory,
            'keywords' => $this->keywords,
            'has_scripts' => $this->hasScripts,
            'has_references' => $this->hasReferences,
            'has_assets' => $this->hasAssets,
            'license' => $this->license,
        ];
    }
}

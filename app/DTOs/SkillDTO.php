<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents a skill with all its metadata.
 *
 * Used by SkillSearchService::getAllSkills() and getSkill() to return
 * structured skill data instead of arrays.
 */
final readonly class SkillDTO
{
    /**
     * @param string $name Skill name
     * @param string $dirName Directory name
     * @param string $description Skill description
     * @param string $path Full path to SKILL.md file
     * @param string $directory Full path to skill directory
     * @param array<string> $keywords Extracted keywords
     * @param bool $hasScripts Has scripts directory
     * @param bool $hasReferences Has references directory
     * @param bool $hasAssets Has assets directory
     * @param string|null $license License if specified
     * @param string $sourceType Where skill comes from (local, installed, etc.)
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
        public string $sourceType = 'unknown',
    ) {}

    /**
     * Check if this skill has any additional resources.
     */
    public function hasResources(): bool
    {
        return $this->hasScripts || $this->hasReferences || $this->hasAssets;
    }

    /**
     * Create from an array (for backward compatibility).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            dirName: $data['dir_name'] ?? basename($data['directory'] ?? dirname($data['path'])),
            description: $data['description'] ?? '',
            path: $data['path'],
            directory: $data['directory'] ?? dirname($data['path']),
            keywords: $data['keywords'] ?? [],
            hasScripts: $data['has_scripts'] ?? false,
            hasReferences: $data['has_references'] ?? false,
            hasAssets: $data['has_assets'] ?? false,
            license: $data['license'] ?? null,
            sourceType: $data['source_type'] ?? 'unknown',
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
            'source_type' => $this->sourceType,
        ];
    }
}

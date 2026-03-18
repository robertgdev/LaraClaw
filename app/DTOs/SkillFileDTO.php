<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents a file within a skill directory.
 *
 * Used for skill references, scripts, and assets.
 * Returned by SkillSearchService::getSkillReferences() and getSkillScripts().
 */
final readonly class SkillFileDTO
{
    public function __construct(
        public string $name,
        public string $path,
    ) {}

    /**
     * Get the file extension.
     */
    public function getExtension(): string
    {
        return pathinfo($this->name, PATHINFO_EXTENSION);
    }

    /**
     * Check if this is a script file.
     */
    public function isScript(): bool
    {
        $ext = strtolower($this->getExtension());
        return in_array($ext, ['sh', 'py', 'js', 'php', 'rb']);
    }

    /**
     * Check if this is a reference document.
     */
    public function isReference(): bool
    {
        $ext = strtolower($this->getExtension());
        return in_array($ext, ['md', 'txt', 'pdf', 'doc', 'docx']);
    }
}

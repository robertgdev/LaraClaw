<?php

declare(strict_types=1);

namespace App\TypedCollections;

use App\DTOs\SkillFileDTO;
use Gamez\Illuminate\Support\TypedCollection;

/**
 * @extends TypedCollection<array-key, SkillFileDTO>
 */
class SkillFileDTOCollection extends TypedCollection
{
    protected static array $allowedTypes = [SkillFileDTO::class];

    /**
     * Find a file by name.
     */
    public function findByName(string $name): ?SkillFileDTO
    {
        return $this->first(fn (SkillFileDTO $file) => $file->name === $name);
    }

    /**
     * Filter only script files.
     */
    public function onlyScripts(): self
    {
        return $this->filter(fn (SkillFileDTO $file) => $file->isScript());
    }

    /**
     * Filter only reference files.
     */
    public function onlyReferences(): self
    {
        return $this->filter(fn (SkillFileDTO $file) => $file->isReference());
    }

    /**
     * Get all file paths.
     *
     * @return array<string>
     */
    public function getPaths(): array
    {
        return $this->map(fn (SkillFileDTO $file) => $file->path)->values()->all();
    }
}

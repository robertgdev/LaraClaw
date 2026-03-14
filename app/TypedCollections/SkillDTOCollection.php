<?php

declare(strict_types=1);

namespace App\TypedCollections;

use App\DTOs\SkillDTO;
use Gamez\Illuminate\Support\TypedCollection;

/**
 * @extends TypedCollection<array-key, SkillDTO>
 */
class SkillDTOCollection extends TypedCollection
{
    protected static array $allowedTypes = [SkillDTO::class];

    /**
     * Find a skill by name.
     */
    public function findByName(string $name): ?SkillDTO
    {
        return $this->first(fn (SkillDTO $skill) => $skill->name === $name);
    }

    /**
     * Filter skills that have scripts.
     */
    public function withScripts(): self
    {
        return $this->filter(fn (SkillDTO $skill) => $skill->hasScripts);
    }

    /**
     * Filter skills that have references.
     */
    public function withReferences(): self
    {
        return $this->filter(fn (SkillDTO $skill) => $skill->hasReferences);
    }

    /**
     * Get all skill names.
     *
     * @return array<string>
     */
    public function getNames(): array
    {
        return $this->map(fn (SkillDTO $skill) => $skill->name)->values()->all();
    }
}

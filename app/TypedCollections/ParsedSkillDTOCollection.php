<?php

declare(strict_types=1);

namespace App\TypedCollections;

use App\DTOs\ParsedSkillDTO;
use Gamez\Illuminate\Support\TypedCollection;

/**
 * @extends TypedCollection<array-key, ParsedSkillDTO>
 */
class ParsedSkillDTOCollection extends TypedCollection
{
    protected static array $allowedTypes = [ParsedSkillDTO::class];

    /**
     * Find a skill by name.
     */
    public function findByName(string $name): ?ParsedSkillDTO
    {
        return $this->first(fn (ParsedSkillDTO $skill) => $skill->name === $name);
    }

    /**
     * Filter skills that have scripts.
     */
    public function withScripts(): self
    {
        return $this->filter(fn (ParsedSkillDTO $skill) => $skill->hasScripts);
    }

    /**
     * Filter skills that have references.
     */
    public function withReferences(): self
    {
        return $this->filter(fn (ParsedSkillDTO $skill) => $skill->hasReferences);
    }

    /**
     * Filter skills that have assets.
     */
    public function withAssets(): self
    {
        return $this->filter(fn (ParsedSkillDTO $skill) => $skill->hasAssets);
    }

    /**
     * Get all skill names.
     *
     * @return array<string>
     */
    public function getNames(): array
    {
        return $this->map(fn (ParsedSkillDTO $skill) => $skill->name)->values()->all();
    }

    /**
     * Create from an array of ParsedSkillDTO instances.
     *
     * @param  array<string, ParsedSkillDTO>  $skills  Keyed by skill name
     */
    public static function fromKeyedArray(array $skills): self
    {
        return new self(array_values($skills));
    }

    /**
     * Convert to array keyed by skill name.
     *
     * @return array<string, ParsedSkillDTO>
     */
    public function toKeyedByName(): array
    {
        return $this->keyBy(fn (ParsedSkillDTO $skill) => $skill->name)->all();
    }
}

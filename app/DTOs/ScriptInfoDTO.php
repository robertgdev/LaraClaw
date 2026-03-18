<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents extracted skill and script information from a script path.
 *
 * Used by ScriptPathResolver to return the skill name and script name
 * extracted from various script path formats.
 */
final readonly class ScriptInfoDTO
{
    public function __construct(
        public string $skill,
        public string $script,
    ) {}
}

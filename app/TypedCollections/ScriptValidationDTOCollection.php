<?php

declare(strict_types=1);

namespace App\TypedCollections;

use App\DTOs\ScriptValidationDTO;
use Gamez\Illuminate\Support\TypedCollection;

/**
 * @extends TypedCollection<array-key, ScriptValidationDTO>
 */
class ScriptValidationDTOCollection extends TypedCollection
{
    protected static array $allowedTypes = [ScriptValidationDTO::class];

    /**
     * Get valid scripts.
     */
    public function valid(): self
    {
        return $this->filter(fn (ScriptValidationDTO $validation) => $validation->valid);
    }

    /**
     * Get invalid scripts.
     */
    public function invalid(): self
    {
        return $this->filter(fn (ScriptValidationDTO $validation) => ! $validation->valid);
    }

    /**
     * Check if all scripts are valid.
     */
    public function allValid(): bool
    {
        return $this->every(fn (ScriptValidationDTO $validation) => $validation->valid);
    }

    /**
     * Check if any script is invalid.
     */
    public function hasInvalid(): bool
    {
        return $this->invalid()->isNotEmpty();
    }

    /**
     * Get scripts with a specific error.
     */
    public function withError(string $error): self
    {
        return $this->filter(fn (ScriptValidationDTO $validation) => $validation->error === $error);
    }

    /**
     * Get validation for a specific command.
     */
    public function forCommand(string $command): ?ScriptValidationDTO
    {
        return $this->first(fn (ScriptValidationDTO $validation) => $validation->command === $command);
    }

    /**
     * Get all error messages.
     *
     * @return array<string>
     */
    public function getErrorMessages(): array
    {
        return $this->invalid()
            ->map(fn (ScriptValidationDTO $validation) => $validation->error)
            ->filter()
            ->values()
            ->all();
    }
}

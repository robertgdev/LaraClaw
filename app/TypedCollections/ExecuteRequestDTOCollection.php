<?php

declare(strict_types=1);

namespace App\TypedCollections;

use App\DTOs\ExecuteRequestDTO;
use Gamez\Illuminate\Support\TypedCollection;

/**
 * @extends TypedCollection<array-key, ExecuteRequestDTO>
 */
class ExecuteRequestDTOCollection extends TypedCollection
{
    protected static array $allowedTypes = [ExecuteRequestDTO::class];

    /**
     * Get requests for a specific script.
     */
    public function forScript(string $script): self
    {
        return $this->filter(fn (ExecuteRequestDTO $request) => $request->script === $script);
    }

    /**
     * Get requests that have arguments.
     */
    public function withArguments(): self
    {
        return $this->filter(fn (ExecuteRequestDTO $request) => ! empty($request->args));
    }

    /**
     * Get requests that are script executions.
     */
    public function scripts(): self
    {
        return $this->filter(fn (ExecuteRequestDTO $request) => $request->isScript());
    }

    /**
     * Check if all requests are script executions.
     */
    public function allScripts(): bool
    {
        return $this->every(fn (ExecuteRequestDTO $request) => $request->isScript());
    }

    /**
     * Check if any request has arguments.
     */
    public function hasWithArguments(): bool
    {
        return $this->withArguments()->isNotEmpty();
    }

    /**
     * Get unique script paths.
     *
     * @return array<string|null>
     */
    public function getScripts(): array
    {
        return $this->map(fn (ExecuteRequestDTO $request) => $request->script)
            ->unique()
            ->values()
            ->all();
    }
}

<?php

declare(strict_types=1);

namespace App\TypedCollections;

use App\DTOs\SessionHistoryEntryDTO;
use Gamez\Illuminate\Support\TypedCollection;

/**
 * @extends TypedCollection<array-key, SessionHistoryEntryDTO>
 */
class SessionHistoryEntryDTOCollection extends TypedCollection
{
    protected static array $allowedTypes = [SessionHistoryEntryDTO::class];

    /**
     * Get entries by role (user/assistant).
     */
    public function byRole(string $role): self
    {
        return $this->filter(fn (SessionHistoryEntryDTO $entry) => $entry->role === $role);
    }

    /**
     * Get only user entries.
     */
    public function userEntries(): self
    {
        return $this->byRole('user');
    }

    /**
     * Get only assistant entries.
     */
    public function assistantEntries(): self
    {
        return $this->byRole('assistant');
    }

    /**
     * Get entries as conversation format (alternating user/assistant).
     */
    public function asConversation(): self
    {
        return $this->values();
    }

    /**
     * Get the most recent entry.
     */
    public function mostRecent(): ?SessionHistoryEntryDTO
    {
        return $this->last();
    }

    /**
     * Get the oldest entry.
     */
    public function oldest(): ?SessionHistoryEntryDTO
    {
        return $this->first();
    }

    /**
     * Get all contents as an array.
     *
     * @return array<string>
     */
    public function getContents(): array
    {
        return $this->map(fn (SessionHistoryEntryDTO $entry) => $entry->content)
            ->values()
            ->all();
    }
}

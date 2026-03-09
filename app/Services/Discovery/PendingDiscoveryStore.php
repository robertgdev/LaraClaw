<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\DTOs\SkillDiscoveryResultDTO;
use Illuminate\Support\Facades\Cache;

/**
 * Manages pending skill discovery state in cache.
 *
 * Stores, retrieves, and parses user responses to skill
 * discovery prompts using a cache backend with TTL.
 */
class PendingDiscoveryStore
{
    protected int $ttl;

    public function __construct(int $ttl = 300)
    {
        $this->ttl = $ttl;
    }

    /**
     * Store a pending discovery for later user selection.
     */
    public function store(
        string $senderId,
        SkillDiscoveryResultDTO $result,
        string $originalMessage,
        string $agentId
    ): void {
        Cache::put("pending_discovery:{$senderId}", [
            'result' => $result->toArray(),
            'original_message' => $originalMessage,
            'agent_id' => $agentId,
        ], $this->ttl);
    }

    /**
     * Get a pending discovery for a sender.
     *
     * @return array{result: SkillDiscoveryResultDTO, original_message: string, agent_id: string}|null
     */
    public function get(string $senderId): ?array
    {
        $data = Cache::get("pending_discovery:{$senderId}");

        if (! $data) {
            return null;
        }

        return [
            'result' => SkillDiscoveryResultDTO::fromArray($data['result']),
            'original_message' => $data['original_message'],
            'agent_id' => $data['agent_id'],
        ];
    }

    /**
     * Clear a pending discovery.
     */
    public function clear(string $senderId): void
    {
        Cache::forget("pending_discovery:{$senderId}");
    }

    /**
     * Check if a message is a response to a pending discovery prompt.
     *
     * @return array{is_selection: bool, index: int|null, skip: bool}
     */
    public function parseResponse(string $message, string $senderId): array
    {
        $pending = $this->get($senderId);

        if (! $pending) {
            return ['is_selection' => false, 'index' => null, 'skip' => false];
        }

        $selection = strtolower(trim($message));

        // User wants to skip
        if (in_array($selection, ['skip', 'cancel', 'no', 'none'])) {
            return ['is_selection' => true, 'index' => null, 'skip' => true];
        }

        // User selected a number
        if (is_numeric($selection)) {
            $index = (int) $selection - 1; // Convert to 0-based index
            $maxIndex = count($pending['result']->matches) - 1;

            if ($index >= 0 && $index <= $maxIndex) {
                return ['is_selection' => true, 'index' => $index, 'skip' => false];
            }
        }

        // Invalid response, not a selection
        return ['is_selection' => false, 'index' => null, 'skip' => false];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\Enums\ChannelEnum;
use App\Models\Conversation;
use Illuminate\Support\Collection;

/**
 * Manages conversation session lifecycle.
 *
 * Extracted from the Conversation model to separate session state-machine
 * logic (start, activate, switch, deactivate) from the Eloquent model.
 *
 * The Conversation model retains backward-compatible static proxies that
 * delegate here, so existing code calling Conversation::startNewSession()
 * etc. continues to work.
 */
class ConversationSessionManager
{
    /**
     * Start a new session for a sender, deactivating all other sessions
     * for the same sender+channel combination.
     *
     * @param  array<string, mixed>  $data
     */
    public function startNewSession(array $data): Conversation
    {
        if (! empty($data['sender_id']) && ! empty($data['channel'])) {
            Conversation::forSender($data['sender_id'], $data['channel'])
                ->update(['is_active' => false]);
        }

        return Conversation::createNew(array_merge($data, [
            'is_active' => true,
        ]));
    }

    /**
     * Get the active session for a sender+channel.
     */
    public function getActiveSession(string $senderId, ChannelEnum $channel): ?Conversation
    {
        return Conversation::forSender($senderId, $channel)
            ->active()
            ->latest('last_message_at')
            ->first();
    }

    /**
     * Get or create an active session for a sender+channel.
     */
    public function getOrCreateActiveSession(string $senderId, ChannelEnum $channel, string $sender = 'user'): Conversation
    {
        $session = $this->getActiveSession($senderId, $channel);

        if ($session) {
            return $session;
        }

        return $this->startNewSession([
            'channel' => $channel,
            'sender' => $sender,
            'sender_id' => $senderId,
        ]);
    }

    /**
     * Get all sessions for a sender+channel.
     *
     * @return Collection<int, Conversation>
     */
    public function getSessionsForSender(string $senderId, ChannelEnum $channel, int $limit = 50): Collection
    {
        return Conversation::forSender($senderId, $channel)
            ->orderByDesc('is_pinned')
            ->orderByDesc('last_message_at')
            ->take($limit)
            ->get();
    }

    /**
     * Activate a session, deactivating all sibling sessions.
     */
    public function activate(Conversation $conversation): void
    {
        Conversation::forSender($conversation->sender_id, $conversation->channel)
            ->where('id', '!=', $conversation->id)
            ->update(['is_active' => false]);

        $conversation->update(['is_active' => true]);
    }
}

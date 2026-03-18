<?php

namespace App\Services;

use App\DTOs\SessionHistoryEntryDTO;
use App\DTOs\SessionIntentResultDTO;
use App\Enums\ChannelEnum;
use App\Logging\MultiLogger;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\TypedCollections\SessionHistoryEntryDTOCollection;
use Illuminate\Support\Collection;
use function Safe\preg_match;

/**
 * SessionService - Manages conversation sessions (WebClaw-style).
 *
 * Each conversation IS a session. This service provides:
 * - Session creation and activation
 * - Session listing and switching
 * - Session renaming and pinning
 * - Intent detection for session commands
 */
class SessionService
{
    /**
     * Session-related intents that should be handled before agent routing.
     *
     * @var array<string>
     */
    public const SESSION_INTENTS = [
        'new_session',
        'show_sessions',
        'switch_session',
        'rename_session',
        'pin_session',
        'delete_session',
    ];

    /**
     * Create a new session for a sender.
     */
    public function createSession(
        ChannelEnum $channel,
        string $senderId,
        string $sender = 'user',
        ?string $label = null
    ): Conversation {
        $session = Conversation::startNewSession([
            'channel' => $channel,
            'sender' => $sender,
            'sender_id' => $senderId,
            'label' => $label,
        ]);

        MultiLogger::info("Session created: {$session->conversation_id}", [
            'channel' => $channel->value,
            'sender_id' => $senderId,
        ]);

        return $session;
    }

    /**
     * Get the active session for a sender, or create one if none exists.
     */
    public function getOrCreateActiveSession(
        ChannelEnum $channel,
        string $senderId,
        string $sender = 'user'
    ): Conversation {
        return Conversation::getOrCreateActiveSession($senderId, $channel, $sender);
    }

    /**
     * Get all sessions for a sender.
     *
     * @return Collection<int, Conversation>
     */
    public function getSessions(ChannelEnum $channel, string $senderId, int $limit = 50): Collection
    {
        return Conversation::getSessionsForSender($senderId, $channel, $limit);
    }

    /**
     * Switch to a specific session.
     */
    public function switchToSession(string $conversationId, ChannelEnum $channel, string $senderId): ?Conversation
    {
        $session = Conversation::where('conversation_id', $conversationId)
            ->forSender($senderId, $channel)
            ->first();

        if (! $session) {
            return null;
        }

        $session->activate();

        MultiLogger::info("Session switched: {$conversationId}", [
            'channel' => $channel->value,
            'sender_id' => $senderId,
        ]);

        return $session;
    }

    /**
     * Rename a session.
     */
    public function renameSession(string $conversationId, string $label, ChannelEnum $channel, string $senderId): bool
    {
        $session = Conversation::where('conversation_id', $conversationId)
            ->forSender($senderId, $channel)
            ->first();

        if (! $session) {
            return false;
        }

        $session->rename($label);

        MultiLogger::info("Session renamed: {$conversationId} -> {$label}", [
            'channel' => $channel->value,
            'sender_id' => $senderId,
        ]);

        return true;
    }

    /**
     * Toggle pin status for a session.
     */
    public function togglePin(string $conversationId, ChannelEnum $channel, string $senderId): ?bool
    {
        $session = Conversation::where('conversation_id', $conversationId)
            ->forSender($senderId, $channel)
            ->first();

        if (! $session) {
            return null;
        }

        $isPinned = $session->togglePin();

        MultiLogger::info("Session pin toggled: {$conversationId}", [
            'is_pinned' => $isPinned,
            'channel' => $channel->value,
            'sender_id' => $senderId,
        ]);

        return $isPinned;
    }

    /**
     * Delete a session (soft delete).
     */
    public function deleteSession(string $conversationId, ChannelEnum $channel, string $senderId): bool
    {
        $session = Conversation::where('conversation_id', $conversationId)
            ->forSender($senderId, $channel)
            ->first();

        if (! $session) {
            return false;
        }

        $session->delete();

        MultiLogger::info("Session deleted: {$conversationId}", [
            'channel' => $channel->value,
            'sender_id' => $senderId,
        ]);

        return true;
    }

    /**
     * Format sessions for display.
     *
     * @param  Collection<int, Conversation>  $sessions
     */
    public function formatSessionList(Collection $sessions): string
    {
        if ($sessions->isEmpty()) {
            return "No sessions found. Type 'new session' to start one.";
        }

        $lines = ['Your sessions:'];

        foreach ($sessions as $index => $session) {
            $number = $index + 1;
            $title = $session->getDisplayTitle();
            $pinned = $session->is_pinned ? '📌 ' : '';
            $active = $session->is_active ? ' (active)' : '';
            $messageCount = $session->total_messages;

            $lines[] = "{$pinned}{$number}. {$title}{$active} [{$messageCount} messages]";
        }

        $lines[] = '';
        $lines[] = "Type a number to switch, or 'new session' to start fresh.";

        return implode("\n", $lines);
    }

    /**
     * Detect if a message is a session-related command.
     */
    public function detectSessionIntent(string $message): ?string
    {
        $message = strtolower(trim($message));

        // New session patterns
        if (preg_match('/^(new|start)\s*(session|conversation|chat)$/i', $message)) {
            return 'new_session';
        }

        // Show sessions patterns
        if (preg_match('/^(show|list)\s*(my\s*)?(sessions|conversations|chats)$/i', $message)) {
            return 'show_sessions';
        }

        // Switch session by number
        if (preg_match('/^(\d+)$/', $message, $matches)) {
            return 'switch_session';
        }

        // Rename session patterns
        if (preg_match('/^rename\s+(session\s+)?(.+?)\s+to\s+(.+)$/i', $message, $matches)) {
            return 'rename_session';
        }

        // Pin session patterns
        if (preg_match('/^(pin|unpin)\s+(session\s+)?(\d+)$/i', $message, $matches)) {
            return 'pin_session';
        }

        // Delete session patterns
        if (preg_match('/^delete\s+(session\s+)?(\d+)$/i', $message, $matches)) {
            return 'delete_session';
        }

        return null;
    }

    /**
     * Handle a session intent and return the response.
     */
    public function handleSessionIntent(
        string $intent,
        string $message,
        ChannelEnum $channel,
        string $senderId,
        string $sender = 'user'
    ): SessionIntentResultDTO {
        switch ($intent) {
            case 'new_session':
                $session = $this->createSession($channel, $senderId, $sender);

                return new SessionIntentResultDTO(
                    handled: true,
                    response: 'Started new session. What would you like to discuss?',
                    session: $session,
                );

            case 'show_sessions':
                $sessions = $this->getSessions($channel, $senderId);

                return new SessionIntentResultDTO(
                    handled: true,
                    response: $this->formatSessionList($sessions),
                    session: null,
                );

            case 'switch_session':
                $sessionNumber = (int) trim($message) - 1;
                $sessions = $this->getSessions($channel, $senderId);

                if (! isset($sessions[$sessionNumber])) {
                    return new SessionIntentResultDTO(
                        handled: true,
                        response: "Invalid session number. Type 'show sessions' to see available sessions.",
                        session: null,
                    );
                }

                $targetSession = $sessions[$sessionNumber];
                $targetSession->activate();

                return new SessionIntentResultDTO(
                    handled: true,
                    response: "Switched to: {$targetSession->getDisplayTitle()}",
                    session: $targetSession,
                );

            case 'rename_session':
                if (preg_match('/^rename\s+(session\s+)?(.+?)\s+to\s+(.+)$/i', $message, $matches)) {
                    $newLabel = trim($matches[3]);
                    $activeSession = $this->getOrCreateActiveSession($channel, $senderId, $sender);
                    $activeSession->rename($newLabel);

                    return new SessionIntentResultDTO(
                        handled: true,
                        response: "Session renamed to: {$newLabel}",
                        session: $activeSession,
                    );
                }
                break;

            case 'pin_session':
                if (preg_match('/^(pin|unpin)\s+(session\s+)?(\d+)$/i', $message, $matches)) {
                    $action = strtolower($matches[1]);
                    $sessionNumber = (int) $matches[3] - 1;
                    $sessions = $this->getSessions($channel, $senderId);

                    if (! isset($sessions[$sessionNumber])) {
                        return new SessionIntentResultDTO(
                            handled: true,
                            response: 'Invalid session number.',
                            session: null,
                        );
                    }

                    $targetSession = $sessions[$sessionNumber];

                    // Toggle to desired state
                    if (($action === 'pin' && ! $targetSession->is_pinned) ||
                        ($action === 'unpin' && $targetSession->is_pinned)) {
                        $targetSession->togglePin();
                    }

                    $status = $action === 'pin' ? 'pinned' : 'unpinned';

                    return new SessionIntentResultDTO(
                        handled: true,
                        response: "Session {$status}: {$targetSession->getDisplayTitle()}",
                        session: null,
                    );
                }
                break;

            case 'delete_session':
                if (preg_match('/^delete\s+(session\s+)?(\d+)$/i', $message, $matches)) {
                    $sessionNumber = (int) $matches[2] - 1;
                    $sessions = $this->getSessions($channel, $senderId);

                    if (! isset($sessions[$sessionNumber])) {
                        return new SessionIntentResultDTO(
                            handled: true,
                            response: 'Invalid session number.',
                            session: null,
                        );
                    }

                    $targetSession = $sessions[$sessionNumber];
                    $title = $targetSession->getDisplayTitle();
                    $targetSession->delete();

                    return new SessionIntentResultDTO(
                        handled: true,
                        response: "Session deleted: {$title}",
                        session: null,
                    );
                }
                break;
        }

        return new SessionIntentResultDTO(
            handled: false,
            response: null,
            session: null,
        );
    }

    /**
     * Get session history for context.
     */
    public function getSessionHistory(string $conversationId, int $limit = 50): SessionHistoryEntryDTOCollection
    {
        $messages = ConversationMessage::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc')
            ->take($limit)
            ->get();

        $history = [];
        foreach ($messages as $message) {
            $history[] = new SessionHistoryEntryDTO(
                role: $message->direction === 'incoming' ? 'user' : 'assistant',
                content: $message->message,
            );
        }

        return new SessionHistoryEntryDTOCollection($history);
    }
}

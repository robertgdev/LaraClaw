<?php

namespace App\Services;

use App\Enums\ChannelEnum;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Chat History Service - Database-backed chat history storage with Laravel Scout search.
 *
 * Saves conversation history to the database for searchability and analytics.
 * Uses normalized structure: conversations table for metadata, conversation_messages for content.
 * Optionally exports to markdown files for backup/compatibility.
 */
class ConversationHistoryService
{
    protected string $chatsDir;

    protected SettingsService $settings;

    protected bool $exportToFiles;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
        $this->chatsDir = config('laraclaw.workspace.path').'/chats';
        $this->exportToFiles = config('laraclaw.chat_history.export_to_files', false);
    }

    /**
     * Save a conversation to the database.
     *
     * @param  string  $channel  The channel (telegram, discord, whatsapp, cli)
     * @param  string  $sender  The sender name
     * @param  string  $userMessage  The original user message
     * @param  array  $responses  Array of [agentId, agentName, response] tuples
     * @param  string|null  $teamId  Optional team ID for team conversations
     * @param  array  $files  Optional array of file paths
     * @param  string|null  $senderId  Optional sender ID for episodic memory tracking
     * @return string The conversation ID
     */
    public function saveConversation(
        string $channel,
        string $sender,
        string $userMessage,
        array $responses,
        ?string $teamId = null,
        array $files = [],
        ?string $senderId = null
    ): string {
        // Convert channel string to enum if needed
        $channelEnum = $channel instanceof ChannelEnum
            ? $channel
            : ChannelEnum::tryFrom(strtolower($channel));

        // Generate sender_id if not provided
        if ($senderId === null) {
            $senderId = $this->generateSenderId($channelEnum, $sender);
        }

        // Create conversation metadata record
        $conversation = Conversation::createNew([
            'channel' => $channelEnum,
            'sender' => $sender,
            'sender_id' => $senderId,
            'team_id' => $teamId,
        ]);

        // Add user message with sender_id
        $conversation->addUserMessage($userMessage, $sender, $senderId, $files);

        // Add each agent response
        foreach ($responses as $response) {
            $conversation->addAgentResponse(
                $response['agentId'] ?? 'unknown',
                $response['agentName'] ?? 'Agent',
                $response['response'] ?? '',
                $response['provider'] ?? null,
                $response['model'] ?? null
            );
        }

        // Mark conversation as completed
        $conversation->markCompleted();

        // Optionally export to file for backup
        if ($this->exportToFiles) {
            $this->exportToFile($conversation);
        }

        return $conversation->conversation_id;
    }

    /**
     * Save a single-agent conversation.
     */
    public function saveSingleAgentConversation(
        string $channel,
        string $sender,
        string $userMessage,
        string $agentId,
        string $agentName,
        string $response,
        array $files = [],
        ?string $provider = null,
        ?string $model = null,
        ?string $senderId = null
    ): string {
        return $this->saveConversation(
            $channel,
            $sender,
            $userMessage,
            [['agentId' => $agentId, 'agentName' => $agentName, 'provider' => $provider, 'model' => $model, 'response' => $response]],
            null,
            $files,
            $senderId
        );
    }

    /**
     * Save a team conversation.
     */
    public function saveTeamConversation(
        string $channel,
        string $sender,
        string $userMessage,
        string $teamId,
        array $responses,
        array $files = [],
        ?string $senderId = null
    ): string {
        return $this->saveConversation(
            $channel,
            $sender,
            $userMessage,
            $responses,
            $teamId,
            $files,
            $senderId
        );
    }

    /**
     * Export a conversation to a markdown file.
     */
    protected function exportToFile(Conversation $conversation): string
    {
        // Load messages if not already loaded
        $conversation->load('messages');

        $lines = [];

        // Header - use relationship to get team
        if ($conversation->team_id) {
            $team = $conversation->team;
            $teamName = $team?->name ?? $conversation->team_id;
            $lines[] = "# Team Conversation: {$teamName} (@{$conversation->team_id})";
        } else {
            $lines[] = '# Conversation';
        }

        $lines[] = '**Date:** '.$conversation->created_at->toISOString();
        $lines[] = "**Channel:** {$conversation->channel->value} | **Sender:** {$conversation->sender}";
        $lines[] = "**Messages:** {$conversation->total_messages}";
        $lines[] = '';
        $lines[] = '------';
        $lines[] = '';

        // Add all messages
        foreach ($conversation->messages as $message) {
            if ($message->isIncoming()) {
                $lines[] = '## User Message';
                $lines[] = '';
                $lines[] = $message->message;
                $lines[] = '';

                // Add file references if any
                if (! empty($message->files)) {
                    $lines[] = '**Files:**';
                    foreach ($message->files as $file) {
                        $lines[] = '- '.basename($file);
                    }
                    $lines[] = '';
                }
            } else {
                $agentName = $message->sender;
                $agentId = $message->agent_id ?? 'unknown';

                $lines[] = '------';
                $lines[] = '';
                $lines[] = "## {$agentName} (@{$agentId})";
                $lines[] = '';
                $lines[] = $message->message;
                $lines[] = '';
            }
        }

        // Determine directory
        $dir = $conversation->team_id
            ? "{$this->chatsDir}/{$conversation->team_id}"
            : $this->chatsDir;

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        // Generate filename with timestamp
        $dateTime = $conversation->created_at->format('Y-m-d_His');
        $filename = "{$conversation->channel->value}_{$dateTime}.md";
        $filePath = "{$dir}/{$filename}";

        File::put($filePath, implode("\n", $lines));

        return $filePath;
    }

    /**
     * Get recent chat history from database.
     *
     * @param  int  $limit  Maximum number of conversations to return
     * @param  string|null  $teamId  Optional team ID to filter by
     * @return array Array of conversation data
     */
    public function getRecentHistory(int $limit = 10, ?string $teamId = null): array
    {
        $query = Conversation::query()
            ->with('messages')
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        return $query->get()
            ->map(fn ($conv) => [
                'id' => $conv->id,
                'conversation_id' => $conv->conversation_id,
                'channel' => $conv->channel->value,
                'sender' => $conv->sender,
                'title' => $this->generateTitle($conv),
                'preview' => Str::limit($conv->getFirstUserMessage()?->message ?? '', 200),
                'date' => $conv->created_at->toDateTimeString(),
                'team_id' => $conv->team_id,
                'total_messages' => $conv->total_messages,
            ])
            ->toArray();
    }

    /**
     * Generate a title for a conversation.
     */
    protected function generateTitle(Conversation $conversation): string
    {
        if ($conversation->team_id) {
            $team = $conversation->team;
            $teamName = $team?->name ?? $conversation->team_id;

            return "Team Conversation: {$teamName}";
        }

        return 'Conversation';
    }

    /**
     * Search conversations using Laravel Scout (when available) or database LIKE query.
     *
     * @param  string  $query  Search query
     * @param  int  $limit  Maximum results
     * @param  string|null  $teamId  Optional team filter
     * @return array Array of matching conversations
     */
    public function search(string $query, int $limit = 20, ?string $teamId = null): array
    {
        $searchQuery = Conversation::searchConversations($query, $limit, $teamId);

        // Scout Builder returns results via get(), same as Eloquent Builder
        // But we need to handle the pagination differently for Scout
        $results = $searchQuery->get();

        return $results->map(fn ($conv) => [
            'id' => $conv->id,
            'conversation_id' => $conv->conversation_id,
            'channel' => $conv->channel->value,
            'sender' => $conv->sender,
            'preview' => Str::limit($conv->getFirstUserMessage()?->message ?? '', 200),
            'date' => $conv->created_at->toDateTimeString(),
            'team_id' => $conv->team_id,
        ])
            ->toArray();
    }

    /**
     * Get a specific conversation by ID.
     */
    public function getConversation(string $conversationId): ?array
    {
        $conversation = Conversation::where('conversation_id', $conversationId)
            ->with('messages')
            ->first();

        if (! $conversation) {
            return null;
        }

        // Build responses array from outgoing messages
        $responses = $conversation->outgoingMessages->map(fn ($msg) => [
            'agentId' => $msg->agent_id,
            'agentName' => $msg->sender,
            'provider' => $msg->provider,
            'model' => $msg->model,
            'response' => $msg->message,
        ])->toArray();

        // Get first user message
        $firstMessage = $conversation->getFirstUserMessage();

        return [
            'id' => $conversation->id,
            'conversation_id' => $conversation->conversation_id,
            'channel' => $conversation->channel->value,
            'sender' => $conversation->sender,
            'sender_id' => $conversation->sender_id,
            'user_message' => $firstMessage?->message,
            'responses' => $responses,
            'team_id' => $conversation->team_id,
            'files' => $firstMessage?->files ?? [],
            'total_messages' => $conversation->total_messages,
            'started_at' => $conversation->started_at?->toDateTimeString(),
            'completed_at' => $conversation->completed_at?->toDateTimeString(),
            'created_at' => $conversation->created_at->toDateTimeString(),
        ];
    }

    /**
     * Delete old chat history from database.
     *
     * @param  int  $daysOld  Delete conversations older than this many days
     * @return int Number of conversations deleted
     */
    public function cleanupOldHistory(int $daysOld = 30): int
    {
        $cutoff = now()->subDays($daysOld);

        $count = Conversation::where('created_at', '<', $cutoff)->count();

        Conversation::where('created_at', '<', $cutoff)->delete();

        return $count;
    }

    /**
     * Get conversation statistics.
     */
    public function getStatistics(): array
    {
        return [
            'total_conversations' => Conversation::count(),
            'total_messages' => ConversationMessage::count(),
            'by_channel' => Conversation::selectRaw('channel, count(*) as count')
                ->groupBy('channel')
                ->pluck('count', 'channel')
                ->toArray(),
            'by_team' => Conversation::whereNotNull('team_id')
                ->selectRaw('team_id, count(*) as count')
                ->groupBy('team_id')
                ->pluck('count', 'team_id')
                ->toArray(),
            'recent_24h' => Conversation::where('created_at', '>=', now()->subDay())->count(),
            'recent_7d' => Conversation::where('created_at', '>=', now()->subDays(7))->count(),
            'recent_30d' => Conversation::where('created_at', '>=', now()->subDays(30))->count(),
        ];
    }

    /**
     * Get the chats directory.
     */
    public function getChatsDir(): string
    {
        return $this->chatsDir;
    }

    /**
     * Get all chat history files (for backward compatibility).
     *
     * @param  string|null  $teamId  Optional team ID to filter by
     * @return array Array of file paths
     */
    public function getHistoryFiles(?string $teamId = null): array
    {
        $dir = $teamId
            ? "{$this->chatsDir}/{$teamId}"
            : $this->chatsDir;

        if (! File::isDirectory($dir)) {
            return [];
        }

        return File::glob("{$dir}/*.md");
    }

    /**
     * Generate a sender_id for episodic memory tracking.
     *
     * @param  ChannelEnum  $channel  The channel type
     * @param  string  $sender  The sender name
     * @return string The generated sender_id
     */
    protected function generateSenderId(ChannelEnum $channel, string $sender): string
    {
        $prefix = match ($channel) {
            ChannelEnum::DISCORD => 'discord',
            ChannelEnum::TELEGRAM => 'telegram',
            ChannelEnum::WHATSAPP => 'whatsapp',
            ChannelEnum::CLI => 'cli',
            ChannelEnum::WEBSOCKET => 'ws',
            default => 'unknown',
        };

        return "{$prefix}_".substr(md5($sender.time()), 0, 8);
    }
}

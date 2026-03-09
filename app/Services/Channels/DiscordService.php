<?php

namespace App\Services\Channels;

use App\Enums\ChannelEnum;
use App\Jobs\ProcessMessageJob;
use App\Logging\MultiLogger;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\PairingEntry;
use App\Services\CommandProcessingService;
use App\Services\SettingsService;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Message as DiscordMessage;
use Discord\Parts\User\User;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Safe\preg_replace;

class DiscordService implements ChannelServiceInterface
{
    protected ?Discord $discord = null;

    protected array $pendingMessages = [];

    protected SettingsService $settings;

    protected CommandProcessingService $commandService;

    /**
     * Create a new DiscordService instance.
     */
    public function __construct(SettingsService $settings, CommandProcessingService $commandService)
    {
        $this->settings = $settings;
        $this->commandService = $commandService;
    }

    /**
     * Initialize the Discord bot.
     */
    public function initialize(): void
    {
        $token = config('laraclaw.channels.discord.bot_token');

        if (! $token || $token === 'your_token_here') {
            throw new \RuntimeException('DISCORD_BOT_TOKEN is not configured');
        }

        $this->discord = new Discord([
            'token' => $token,
            'intents' => Intents::getDefaultIntents() | Intents::MESSAGE_CONTENT,
        ]);

        MultiLogger::info('Discord bot initialized');
    }

    /**
     * Get the Discord instance.
     */
    public function getDiscord(): Discord
    {
        if (! $this->discord) {
            $this->initialize();
        }

        return $this->discord;
    }

    /**
     * Check if the service is ready.
     */
    public function isReady(): bool
    {
        return $this->discord !== null;
    }

    /**
     * Start polling for updates.
     */
    public function startPolling(): void
    {
        $discord = $this->getDiscord();
        $pollingInterval = $this->settings->getPollingInterval('discord');

        $discord->on('init', function (Discord $discord) {
            MultiLogger::info("Discord bot connected as {$discord->user->username}");
            MultiLogger::info('Listening for DMs and guild mentions...');
        });

        $discord->on(Event::MESSAGE_CREATE, function (DiscordMessage $message, Discord $discord) {
            $this->handleIncomingMessage($message);
        });

        // Process outgoing queue periodically
        $discord->getLoop()->addPeriodicTimer($pollingInterval, function () {
            $this->processOutgoingQueue();
        });

        $discord->run();
    }

    /**
     * Handle an incoming message from Discord.
     */
    protected function handleIncomingMessage(DiscordMessage $message): void
    {
        // Skip bot messages
        if ($message->author->bot) {
            return;
        }

        // For guild (server) messages, only respond when bot is mentioned
        $isGuildMessage = $message->guild !== null;
        $isMentioned = false;

        if ($isGuildMessage) {
            $discord = $this->getDiscord();
            $botUserId = $discord->user->id;
            $isMentioned = $message->mentions->has($botUserId);

            if (! $isMentioned) {
                return; // Skip guild messages where bot isn't mentioned
            }
        }

        $hasAttachments = $message->attachments->count() > 0;
        $hasContent = ! empty(trim($message->content));

        // Skip messages with no content and no attachments
        if (! $hasContent && ! $hasAttachments) {
            return;
        }

        $sender = $message->author->username;
        $senderId = $message->author->id;
        $messageId = time().'_'.Str::random(7);

        // Download any attachments
        $downloadedFiles = [];
        if ($hasAttachments) {
            foreach ($message->attachments as $attachment) {
                $filePath = $this->downloadAttachment($attachment, $messageId);
                if ($filePath) {
                    $downloadedFiles[] = $filePath;
                }
            }
        }

        $messageText = $message->content;

        // Strip bot mention from guild messages
        if ($isGuildMessage && $isMentioned) {
            $discord = $this->getDiscord();
            $botUserId = $discord->user->id;
            // Remove both <@USER_ID> and <@!USER_ID> formats
            $messageText = preg_replace('/<@!?'.preg_quote($botUserId, '/').'>\s*/', '', $messageText);
            $messageText = trim($messageText);
        }

        MultiLogger::info("Message from {$sender}: ".Str::limit($messageText, 50).
            (count($downloadedFiles) > 0 ? ' [+'.count($downloadedFiles).' file(s)]' : ''));

        // Check pairing
        $pairing = PairingEntry::ensureSenderPaired(ChannelEnum::DISCORD, $senderId, $sender);

        if (! $pairing['approved'] && isset($pairing['code'])) {
            if ($pairing['isNewPending'] ?? false) {
                MultiLogger::info("Blocked unpaired Discord sender {$sender} ({$senderId}) with code {$pairing['code']}");
                $message->reply($this->pairingMessage($pairing['code']));
            }

            return;
        }

        // Handle commands
        if ($this->handleCommands($messageText, $message)) {
            return;
        }

        // Show typing indicator
        $message->channel->broadcastTyping();

        // Build message text with file references
        $fullMessage = $messageText;
        if (! empty($downloadedFiles)) {
            $fileRefs = implode("\n", array_map(fn ($f) => "[file: {$f}]", $downloadedFiles));
            $fullMessage = $fullMessage ? "{$fullMessage}\n\n{$fileRefs}" : $fileRefs;
        }

        // Get or create a conversation for this sender
        $conversation = Conversation::getOrCreateActiveSession($senderId, ChannelEnum::DISCORD, $sender);

        // Create incoming message
        $msg = ConversationMessage::createIncoming([
            'conversation_id' => $conversation->conversation_id,
            'channel' => ChannelEnum::DISCORD,
            'sender' => $sender,
            'sender_id' => $senderId,
            'message' => $fullMessage,
            'files' => $downloadedFiles,
        ]);

        // Store pending message by sender_id (not message_id) so outgoing messages can find it
        $this->pendingMessages[$senderId] = [
            'message' => $message,
            'channel' => $message->channel,
            'timestamp' => time(),
            'message_id' => $msg->message_id, // Keep for debugging/cleanup
        ];

        // Dispatch processing job
        ProcessMessageJob::dispatch($msg);

        MultiLogger::info("Queued message {$msg->message_id}");
    }

    /**
     * Handle bot commands.
     */
    protected function handleCommands(string $messageText, DiscordMessage $message): bool
    {
        // Use CommandProcessingService for command handling
        $response = $this->commandService->handleChannelCommand($messageText);

        if ($response !== null) {
            MultiLogger::info('Channel command handled: '.trim($messageText));
            $message->reply($response->message);

            return true;
        }

        return false;
    }

    /**
     * Check for and send outgoing messages.
     */
    public function processOutgoingQueue(): void
    {
        $messages = ConversationMessage::outgoing()
            ->forChannel(ChannelEnum::DISCORD)
            ->pending()
            ->orderBy('created_at')
            ->get();

        foreach ($messages as $msg) {
            try {
                $this->sendOutgoingMessage($msg);
                $msg->markAsCompleted();
            } catch (\Exception $e) {
                MultiLogger::error("Failed to send Discord message: {$e->getMessage()}");
                $msg->markAsFailed($e->getMessage());
            }
        }
    }

    /**
     * Send an outgoing message.
     */
    protected function sendOutgoingMessage(ConversationMessage $msg): void
    {
        // Look up pending message by sender_id (not message_id)
        $pending = $this->pendingMessages[$msg->sender_id] ?? null;
        $responseData = $msg->toResponseData();

        MultiLogger::debug("DiscordService: sendOutgoingMessage - sender_id={$msg->sender_id}, pending=".($pending ? 'yes' : 'no').", senderId={$responseData['senderId']}");

        // Send files first
        if (! empty($responseData['files'])) {
            $this->sendFiles($pending, $responseData['files'], $responseData['senderId'] ?? null);
        }

        // Send text response
        if (! empty($responseData['message'])) {
            $chunks = $this->splitMessage($responseData['message']);

            if ($pending && isset($pending['message'])) {
                // Reply to original message
                MultiLogger::debug('DiscordService: Replying to original message');
                if (! empty($chunks[0])) {
                    $pending['message']->reply($chunks[0]);
                }
                // Send remaining chunks
                for ($i = 1; $i < count($chunks); $i++) {
                    $pending['channel']->send($chunks[$i]);
                }
            } elseif ($responseData['senderId']) {
                // Proactive message - DM the user directly
                MultiLogger::debug('DiscordService: Using sendDirectMessage path (no pending message found)');
                $this->sendDirectMessage($responseData['senderId'], $chunks, $responseData['files'] ?? []);
            } else {
                MultiLogger::warning('DiscordService: No way to send message - no pending and no senderId');
            }
        }

        MultiLogger::info("Sent response to {$msg->sender} (".strlen($responseData['message'] ?? '').' chars)');

        // Clean up pending by sender_id
        unset($this->pendingMessages[$msg->sender_id]);
    }

    /**
     * Send files to a channel.
     */
    protected function sendFiles(?array $pending, array $files, ?string $senderId): void
    {
        $attachments = [];
        foreach ($files as $file) {
            if (! File::exists($file)) {
                continue;
            }
            $attachments[] = $file;
        }

        if (empty($attachments)) {
            return;
        }

        // Build message with files using MessageBuilder
        $builder = MessageBuilder::new();
        foreach ($attachments as $attachment) {
            $builder->addFile($attachment);
        }

        if ($pending && isset($pending['channel'])) {
            $pending['channel']->sendMessage($builder);
        } elseif ($senderId) {
            // Send via DM using getPrivateChannel()
            $discord = $this->getDiscord();
            $discord->users->fetch($senderId)->then(function (User $user) use ($builder) {
                $user->getPrivateChannel()->then(function ($dm) use ($builder) {
                    $dm->sendMessage($builder);
                });
            });
        }

        MultiLogger::info('Sent '.count($attachments).' file(s) to Discord');
    }

    /**
     * Send a direct message to a user.
     */
    protected function sendDirectMessage(string $userId, array $chunks, array $files): void
    {
        $discord = $this->getDiscord();

        MultiLogger::debug("DiscordService: Starting sendDirectMessage to user {$userId}");

        $discord->users->fetch($userId)->then(function (User $user) use ($chunks, $files, $userId) {
            MultiLogger::debug("DiscordService: Fetched user {$userId}, creating DM channel");
            $user->getPrivateChannel()->then(function ($dm) use ($chunks, $files, $userId) {
                MultiLogger::debug("DiscordService: DM channel created for {$userId}, sending message");
                // Send files first using MessageBuilder
                $validFiles = array_filter($files, fn ($f) => File::exists($f));
                if (! empty($validFiles)) {
                    $fileBuilder = MessageBuilder::new();
                    foreach ($validFiles as $file) {
                        $fileBuilder->addFile($file);
                    }
                    $dm->sendMessage($fileBuilder);
                }
                // Send text chunks
                foreach ($chunks as $chunk) {
                    $dm->sendMessage(MessageBuilder::new()->setContent($chunk))->then(function () use ($userId) {
                        MultiLogger::debug("DiscordService: Message chunk sent successfully to {$userId}");
                    })->otherwise(function (\Exception $e) use ($userId) {
                        MultiLogger::error("DiscordService: Failed to send chunk to {$userId}: {$e->getMessage()}");
                    });
                }
            })->otherwise(function (\Exception $e) use ($userId) {
                MultiLogger::error("DiscordService: Failed to create DM for {$userId}: {$e->getMessage()}");
            });
        })->otherwise(function (\Exception $e) use ($userId) {
            MultiLogger::error("DiscordService: Failed to fetch user {$userId}: {$e->getMessage()}");
        });

        MultiLogger::info("Sent proactive message to Discord user {$userId}");
    }

    /**
     * Download an attachment.
     */
    protected function downloadAttachment($attachment, string $messageId): ?string
    {
        try {
            $attachmentName = $attachment->filename ?? "discord_{$messageId}_".time().'.bin';
            $filename = "discord_{$messageId}_".$this->sanitizeFileName($attachmentName);
            $localPath = $this->buildUniqueFilePath(config('laraclaw.files.dir'), $filename);

            $fileContent = file_get_contents($attachment->url);
            File::put($localPath, $fileContent);

            $contentType = $attachment->content_type ?? 'unknown';
            MultiLogger::info('Downloaded attachment: '.basename($localPath)." ({$contentType})");

            return $localPath;
        } catch (\Exception $e) {
            MultiLogger::error("Failed to download attachment {$attachment->filename}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Split long messages for Discord's 2000 char limit.
     */
    protected function splitMessage(string $text, int $maxLength = 2000): array
    {
        if (strlen($text) <= $maxLength) {
            return [$text];
        }

        $chunks = [];
        $remaining = $text;

        while (strlen($remaining) > 0) {
            if (strlen($remaining) <= $maxLength) {
                $chunks[] = $remaining;
                break;
            }

            // Try to split at newline boundary
            $splitIndex = strrpos(substr($remaining, 0, $maxLength), "\n");

            // Fall back to space boundary
            if ($splitIndex === false || $splitIndex <= 0) {
                $splitIndex = strrpos(substr($remaining, 0, $maxLength), ' ');
            }

            // Hard-cut if no good boundary found
            if ($splitIndex === false || $splitIndex <= 0) {
                $splitIndex = $maxLength;
            }

            $chunks[] = substr($remaining, 0, $splitIndex);
            $remaining = ltrim(substr($remaining, $splitIndex), "\n");
        }

        return $chunks;
    }

    /**
     * Sanitize a file name.
     */
    protected function sanitizeFileName(string $fileName): string
    {
        $baseName = basename($fileName);
        $clean = preg_replace('/[<>:"\/\\|?*\x00-\x1f]/', '_', $baseName);

        return trim($clean) ?: 'file.bin';
    }

    /**
     * Build a unique file path.
     */
    protected function buildUniqueFilePath(string $dir, string $preferredName): string
    {
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $cleanName = $this->sanitizeFileName($preferredName);
        $ext = pathinfo($cleanName, PATHINFO_EXTENSION);
        $stem = pathinfo($cleanName, PATHINFO_FILENAME);
        $candidate = $dir.'/'.$cleanName;
        $counter = 1;

        while (File::exists($candidate)) {
            $candidate = $dir.'/'.$stem.'_'.$counter.'.'.$ext;
            $counter++;
        }

        return $candidate;
    }

    /**
     * Get pairing message.
     */
    protected function pairingMessage(string $code): string
    {
        return implode("\n", [
            'This sender is not paired yet.',
            "Your pairing code: {$code}",
            'Ask the LaraClaw owner to approve you with:',
            "laraclaw pairing approve {$code}",
        ]);
    }

    /**
     * Clean up old pending messages.
     */
    protected function cleanupPendingMessages(): void
    {
        $tenMinutesAgo = time() - 600;
        foreach ($this->pendingMessages as $id => $data) {
            if ($data['timestamp'] < $tenMinutesAgo) {
                unset($this->pendingMessages[$id]);
            }
        }
    }
}

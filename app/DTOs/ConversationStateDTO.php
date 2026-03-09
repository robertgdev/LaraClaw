<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Support\Str;

final class ConversationStateDTO
{
    public string $id;

    public int $pending = 0;

    public int $totalMessages = 0;

    public int $maxMessages = 50;

    public int $startTime;

    /** @var array<string> */
    public array $responses = [];

    /** @var array<string> */
    public array $files = [];

    /** @param array<string, mixed> $teamContext */
    public function __construct(
        public string $channel,
        public string $sender,
        public ?string $senderId,
        public string $originalMessage,
        public ?string $messageId,
        public array $teamContext,
        ?int $maxMessages = null,
    ) {
        $this->id = Str::uuid()->toString();
        $this->startTime = time();
        // Ensure maxMessages is an int (config may return string)
        $this->maxMessages = $maxMessages ?? (int) config('laraclaw.conversation.max_messages', 50);
    }

    public function addResponse(string $agentId, string $response, ?string $agentName = null, ?string $provider = null, ?string $model = null): void
    {
        $this->responses[] = [
            'agentId' => $agentId,
            'agentName' => $agentName ?? $agentId,
            'provider' => $provider,
            'model' => $model,
            'response' => $response,
            'timestamp' => time(),
        ];
        $this->totalMessages++;
    }

    /**
     * @param  array<string>  $files
     */
    public function addFiles(array $files): void
    {
        $this->files = array_unique(array_merge($this->files, $files));
    }

    public function incrementPending(int $count = 1): void
    {
        $this->pending += $count;
    }

    public function decrementPending(): void
    {
        $this->pending = max(0, $this->pending - 1);
    }

    public function isComplete(): bool
    {
        return $this->pending === 0;
    }

    public function isMaxMessagesReached(): bool
    {
        return $this->totalMessages >= $this->maxMessages;
    }

    public function getTeamId(): ?string
    {
        return $this->teamContext['teamId'] ?? null;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'sender' => $this->sender,
            'senderId' => $this->senderId,
            'originalMessage' => $this->originalMessage,
            'messageId' => $this->messageId,
            'pending' => $this->pending,
            'responses' => $this->responses,
            'files' => $this->files,
            'totalMessages' => $this->totalMessages,
            'maxMessages' => $this->maxMessages,
            'teamContext' => $this->teamContext,
            'startTime' => $this->startTime,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $dto = new self(
            channel: $data['channel'],
            sender: $data['sender'],
            senderId: $data['senderId'] ?? null,
            originalMessage: $data['originalMessage'],
            messageId: $data['messageId'] ?? null,
            teamContext: $data['teamContext'],
            maxMessages: $data['maxMessages'] ?? null,
        );

        $dto->id = $data['id'];
        $dto->pending = $data['pending'] ?? 0;
        $dto->responses = $data['responses'] ?? [];
        $dto->files = $data['files'] ?? [];
        $dto->totalMessages = $data['totalMessages'] ?? 0;
        $dto->startTime = $data['startTime'] ?? time();

        return $dto;
    }
}

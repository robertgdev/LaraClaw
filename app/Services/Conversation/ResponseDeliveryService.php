<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\DTOs\ResponsePreparationDTO;
use App\Logging\MultiLogger;
use App\Models\ConversationMessage;
use App\Models\Event;
use Illuminate\Support\Facades\File;

use function Safe\preg_match_all;
use function Safe\preg_replace;

/**
 * Handles response delivery: file collection, long-response handling,
 * and sending responses to the outgoing message queue.
 */
class ResponseDeliveryService
{
    protected int $longResponseThreshold;

    public function __construct(int $longResponseThreshold = 4000)
    {
        $this->longResponseThreshold = $longResponseThreshold;
    }

    /**
     * Collect files from a response text.
     *
     * @return array<int, string>
     */
    public function collectFiles(string $response): array
    {
        $files = [];
        $matches = [];
        preg_match_all('/\[send_file:\s*([^\]]+)\]/', $response, $matches);

        foreach ($matches[1] as $filePath) {
            $filePath = trim($filePath);
            if (File::exists($filePath)) {
                $files[] = $filePath;
            }
        }

        return $files;
    }

    /**
     * Handle long responses by saving to file.
     *
     * @param  array<int, string>  $existingFiles
     */
    public function handleLongResponse(string $response, array $existingFiles): ResponsePreparationDTO
    {
        if (strlen($response) <= $this->longResponseThreshold) {
            return new ResponsePreparationDTO(
                message: $response,
                files: $existingFiles,
            );
        }

        // Save full response as a .md file
        $filename = 'response_'.time().'.md';
        $filesDir = config('laraclaw.files.dir');

        if (! File::isDirectory($filesDir)) {
            File::makeDirectory($filesDir, 0755, true);
        }

        $filePath = $filesDir.'/'.$filename;
        File::put($filePath, $response);

        MultiLogger::info('Long response ('.strlen($response).' chars) saved to '.$filename);

        // Truncate to preview
        $preview = substr($response, 0, $this->longResponseThreshold)."\n\n_(Full response attached as file)_";

        return new ResponsePreparationDTO(
            message: $preview,
            files: array_merge($existingFiles, [$filePath]),
        );
    }

    /**
     * Send a response by creating an outgoing message and emitting events.
     *
     * @param  array<int, string>|null  $files
     */
    public function sendResponse(
        ConversationMessage $originalMessage,
        string $message,
        ?string $agentId = null,
        ?array $files = null,
        ?string $agentName = null,
        ?string $provider = null,
        ?string $model = null
    ): void {
        $sender = $agentName ?? $originalMessage->sender;

        ConversationMessage::createOutgoing([
            'conversation_id' => (string) $originalMessage->id,
            'channel' => $originalMessage->channel,
            'sender' => $sender,
            'sender_id' => $originalMessage->sender_id,
            'message' => $message,
            'agent_id' => $agentId,
            'files' => $files,
            'provider' => $provider,
            'model' => $model,
            'is_llm' => true,
            'reply_to' => $originalMessage->id,
        ]);

        MultiLogger::info("✓ Response ready [{$originalMessage->channel->value}] {$sender} (".strlen($message).' chars)');

        Event::emit('response_ready', [
            'channel' => $originalMessage->channel,
            'sender' => $sender,
            'agentId' => $agentId,
            'responseLength' => strlen($message),
            'responseText' => $message,
            'messageId' => $originalMessage->message_id,
        ]);

        $originalMessage->markAsCompleted();
    }

    /**
     * Prepare a simple (non-team) response: collect files, strip tags, handle long response.
     */
    public function prepareSimpleResponse(string $response): ResponsePreparationDTO
    {
        $finalResponse = trim($response);
        $outboundFiles = $this->collectFiles($finalResponse);

        // Remove [send_file: ...] tags
        if (! empty($outboundFiles)) {
            $finalResponse = preg_replace('/\[send_file:\s*[^\]]+\]/', '', $finalResponse);
            $finalResponse = trim($finalResponse);
        }

        return $this->handleLongResponse($finalResponse, $outboundFiles);
    }
}

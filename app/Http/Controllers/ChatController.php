<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function Safe\json_encode;
use function Safe\ob_flush;

class ChatController extends Controller
{
    /**
     * Get all conversations/sessions
     */
    public function sessions(Request $request): JsonResponse
    {
        $conversations = Conversation::query()
            ->orderBy('updated_at', 'desc')
            ->get();

        $sessions = $conversations->map(function (Conversation $conv): array {
            /** @var ConversationMessage|null $lastMessage */
            $lastMessage = $conv->messages()
                ->orderBy('created_at', 'desc')
                ->first();

            return [
                'key' => $conv->conversation_id,
                'friendlyId' => $conv->conversation_id,
                'title' => $conv->label,
                'derivedTitle' => $conv->derived_title ?? $this->deriveTitle($conv),
                'label' => $conv->label,
                'updatedAt' => $conv->updated_at?->timestamp,
                'lastMessage' => $lastMessage ? [
                    'role' => $lastMessage->direction === 'incoming' ? 'user' : 'assistant',
                    'content' => $lastMessage->message,
                    'timestamp' => $lastMessage->created_at?->timestamp,
                ] : null,
                'totalTokens' => null,
                'contextTokens' => null,
                'feedback' => $conv->feedback?->value,
            ];
        });

        return response()->json(['sessions' => $sessions]);
    }

    /**
     * Create a new conversation/session
     */
    public function createSession(Request $request): JsonResponse
    {
        $uuid = (string) Str::uuid();
        // Generate a unique sender_id for web chat users
        $senderId = 'ws_'.substr($uuid, 0, 8);

        $conversation = Conversation::create([
            'conversation_id' => $uuid,
            'channel' => \App\Enums\ChannelEnum::WEBSOCKET,
            'sender' => 'user',
            'sender_id' => $senderId,
            'is_active' => true,
        ]);

        return response()->json([
            'sessionKey' => $uuid,
            'friendlyId' => $uuid,
        ]);
    }

    /**
     * Get conversation history
     */
    public function history(Request $request): JsonResponse
    {
        $sessionKey = $request->query('sessionKey');
        $friendlyId = $request->query('friendlyId');
        $limit = (int) $request->query('limit', 200);

        $conversation = null;

        if ($sessionKey) {
            $conversation = Conversation::where('conversation_id', $sessionKey)->first();
        } elseif ($friendlyId) {
            $conversation = Conversation::where('conversation_id', $friendlyId)->first();
        }

        if (! $conversation) {
            return response()->json([
                'sessionKey' => $sessionKey ?? $friendlyId ?? '',
                'messages' => [],
            ]);
        }

        $messages = $conversation->messages()
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();

        /** @var \Illuminate\Support\Collection<int, ConversationMessage> $messages */
        $formattedMessages = $messages->map(function (ConversationMessage $msg): array {
            return [
                'id' => $msg->id,
                'messageId' => $msg->message_id,
                'role' => $msg->direction === 'incoming' ? 'user' : 'assistant',
                'content' => [['type' => 'text', 'text' => $msg->message]],
                'timestamp' => $msg->created_at?->timestamp,
                'feedback' => $msg->feedback?->value,
            ];
        });

        return response()->json([
            'sessionKey' => $conversation->conversation_id,
            'sessionId' => $conversation->conversation_id,
            'messages' => $formattedMessages,
        ]);
    }

    /**
     * Send a message to the agent
     */
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sessionKey' => 'nullable|string',
            'friendlyId' => 'nullable|string',
            'message' => 'required|string',
            'thinking' => 'nullable|string',
            'idempotencyKey' => 'nullable|string',
            'attachments' => 'nullable|array',
        ]);

        $sessionKey = $validated['sessionKey'] ?? '';
        $friendlyId = $validated['friendlyId'] ?? '';
        $message = $validated['message'];

        // Find or create conversation
        $conversation = null;
        if ($sessionKey) {
            $conversation = Conversation::where('conversation_id', $sessionKey)->first();
        } elseif ($friendlyId) {
            $conversation = Conversation::where('conversation_id', $friendlyId)->first();
        }

        if (! $conversation) {
            $uuid = $friendlyId ?: (string) Str::uuid();
            // Generate a unique sender_id for web chat users (using conversation UUID as base)
            $senderId = 'ws_'.substr($uuid, 0, 8);
            $conversation = Conversation::create([
                'conversation_id' => $uuid,
                'channel' => \App\Enums\ChannelEnum::WEBSOCKET,
                'sender' => 'user',
                'sender_id' => $senderId,
                'is_active' => true,
                'derived_title' => mb_substr($message, 0, 50).(strlen($message) > 50 ? '...' : ''),
            ]);
        }

        // Ensure sender_id is set (for existing conversations created before the fix)
        $senderId = $conversation->sender_id;
        if (empty($senderId)) {
            $senderId = 'ws_'.substr($conversation->conversation_id, 0, 8);
            $conversation->update(['sender_id' => $senderId]);
        }

        // Store user message with sender_id from conversation
        $userMessage = $conversation->addUserMessage($message, 'user', $senderId, []);

        // Update conversation timestamp
        $conversation->updateDerivedTitle();
        $conversation->touchLastMessage();

        // Dispatch processing job for agent response
        \App\Jobs\ProcessMessageJob::dispatch($userMessage);

        $runId = (string) Str::uuid();

        return response()->json([
            'runId' => $runId,
            'messageId' => $userMessage->id,
        ]);
    }

    /**
     * Delete a conversation/session (soft delete)
     */
    public function deleteSession(Request $request): JsonResponse
    {
        $sessionKey = $request->query('sessionKey');
        $friendlyId = $request->query('friendlyId');

        $conversation = null;

        if ($sessionKey) {
            $conversation = Conversation::where('conversation_id', $sessionKey)->first();
        } elseif ($friendlyId) {
            $conversation = Conversation::where('conversation_id', $friendlyId)->first();
        }

        if ($conversation) {
            // Soft delete the conversation (messages will remain in database)
            $conversation->delete();
        }

        return response()->json(['success' => true]);
    }

    /**
     * Store feedback for a specific message.
     *
     * POST /api/feedback/message
     * Body: { messageId: string, feedback: -1|0|1, comment?: string }
     *
     * Accepts both the UUID (message_id column) and the integer primary key.
     */
    public function feedbackMessage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'messageId' => 'required|string',
            'feedback' => 'required|integer|in:-1,0,1',
            'comment' => 'nullable|string|max:1000',
        ]);

        $messageId = $validated['messageId'];

        // Try UUID lookup first, then fall back to integer PK
        $message = ConversationMessage::where('message_id', $messageId)->first()
            ?? ConversationMessage::find($messageId);

        if (! $message) {
            return response()->json(['error' => 'Message not found'], 404);
        }

        $feedback = \App\Enums\FeedbackEnum::fromInt((int) $validated['feedback']);
        if (! $feedback) {
            return response()->json(['error' => 'Invalid feedback value'], 422);
        }

        $message->setFeedback($feedback, $validated['comment'] ?? null);

        return response()->json([
            'success' => true,
            'message_id' => $message->message_id,
            'feedback' => $feedback->value,
            'feedback_label' => $feedback->label(),
        ]);
    }

    /**
     * Store feedback for a conversation.
     *
     * POST /api/feedback/conversation
     * Body: { conversationId: string, feedback: -1|0|1, comment?: string }
     */
    public function feedbackConversation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversationId' => 'required|string',
            'feedback' => 'required|integer|in:-1,0,1',
            'comment' => 'nullable|string|max:1000',
        ]);

        $conversation = Conversation::where('conversation_id', $validated['conversationId'])->first();

        if (! $conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $feedback = \App\Enums\FeedbackEnum::fromInt((int) $validated['feedback']);
        if (! $feedback) {
            return response()->json(['error' => 'Invalid feedback value'], 422);
        }

        $conversation->setFeedback($feedback, $validated['comment'] ?? null);

        return response()->json([
            'success' => true,
            'conversation_id' => $conversation->conversation_id,
            'feedback' => $feedback->value,
            'feedback_label' => $feedback->label(),
        ]);
    }

    /**
     * Rename a conversation/session
     */
    public function renameSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sessionKey' => 'required|string',
            'title' => 'required|string|max:255',
        ]);

        $conversation = Conversation::where('conversation_id', $validated['sessionKey'])->first();

        if ($conversation) {
            $conversation->update([
                'label' => $validated['title'],
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Stream endpoint for real-time updates (SSE placeholder)
     */
    public function stream(Request $request): StreamedResponse
    {
        $sessionKey = $request->query('sessionKey');
        $friendlyId = $request->query('friendlyId');

        // Set up SSE headers
        return response()->stream(function (): void {
            // Send initial connection message
            echo 'data: '.json_encode(['event' => 'connected'])."\n\n";
            echo "\n\n";

            // Keep connection alive
            // @phpstan-ignore while.alwaysTrue (intentional infinite loop for SSE heartbeat)
            while (true) {
                echo ": heartbeat\n\n";
                ob_flush();
                flush();
                sleep(30);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Ping endpoint for gateway status
     */
    public function ping(): JsonResponse
    {
        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * Derive title from conversation
     */
    private function deriveTitle(Conversation $conversation): string
    {
        /** @var ConversationMessage|null $firstMessage */
        $firstMessage = $conversation->messages()
            ->where('direction', 'incoming')
            ->orderBy('created_at', 'asc')
            ->first();

        if ($firstMessage) {
            $content = $firstMessage->message;
            if (is_string($content)) {
                return mb_substr($content, 0, 50).(strlen($content) > 50 ? '...' : '');
            }
        }

        return 'New Chat';
    }
}

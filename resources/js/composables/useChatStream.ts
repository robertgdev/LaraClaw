import { ref, onUnmounted, watch, type Ref } from 'vue';
import type { GatewayMessage } from '@/types/chat';
import { websocketConfig, getWebSocketUrl } from '@/config/websocket';
import { useAuthStore } from '@/stores/auth';

interface StreamChatPayload {
    runId?: string;
    sessionKey?: string;
    state?: string;
    message?: GatewayMessage;
    seq?: number;
}

interface UseChatStreamOptions {
    sessionKey: Ref<string>;
    friendlyId: Ref<string>;
    onMessage?: (message: GatewayMessage) => void;
    onStateChange?: (state: 'delta' | 'final' | 'error' | 'aborted', runId?: string) => void;
    onHistoryRefresh?: () => void;
    onConnectionChange?: (connected: boolean) => void;
    onConversationId?: (conversationId: string) => void;
}

interface WebSocketMessage {
    type: string;
    payload?: unknown;
    event?: string;
    data?: unknown;
    status?: string;
    message?: string;
}

export function useChatStream(options: UseChatStreamOptions) {
    const { sessionKey, friendlyId, onMessage, onStateChange, onHistoryRefresh, onConnectionChange, onConversationId } = options;

    const authStore = useAuthStore();
    const isConnected = ref(false);
    const error = ref<string | null>(null);
    const connectionStatus = ref<'disconnected' | 'connecting' | 'connected' | 'reconnecting'>('disconnected');
    const isAuthenticated = ref(false);

    let websocket: WebSocket | null = null;
    let reconnectTimer: ReturnType<typeof setTimeout> | null = null;
    let heartbeatTimer: ReturnType<typeof setInterval> | null = null;
    let reconnectAttempt = 0;
    let currentReconnectInterval = websocketConfig.reconnectInterval;

    function stopStream() {
        if (reconnectTimer) {
            clearTimeout(reconnectTimer);
            reconnectTimer = null;
        }
        if (heartbeatTimer) {
            clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }
        if (websocket) {
            websocket.close();
            websocket = null;
        }
        isConnected.value = false;
        connectionStatus.value = 'disconnected';
        onConnectionChange?.(false);
    }

    function startStream() {
        // Always connect to WebSocket server, even without a session
        // Session subscription happens after connection
        stopStream();
        connect();
    }

    function connect() {
        connectionStatus.value = 'connecting';
        error.value = null;

        try {
            const url = getWebSocketUrl();
            console.log('[WebSocket] Connecting to:', url);
            websocket = new WebSocket(url);

            websocket.onopen = handleOpen;
            websocket.onmessage = handleMessage;
            websocket.onerror = handleError;
            websocket.onclose = handleClose;
        } catch (err) {
            console.error('[WebSocket] Connection error:', err);
            error.value = err instanceof Error ? err.message : 'Failed to connect to WebSocket';
            connectionStatus.value = 'disconnected';
            scheduleReconnect();
        }
    }

    function handleOpen() {
        console.log('[WebSocket] Connected');
        isConnected.value = true;
        connectionStatus.value = 'connected';
        reconnectAttempt = 0;
        currentReconnectInterval = websocketConfig.reconnectInterval;

        // Send authentication if we have a token
        if (authStore.token) {
            console.log('[WebSocket] Sending authentication');
            send({
                type: 'auth',
                token: authStore.token,
            });
        } else {
            // No token - close connection and redirect to login
            console.log('[WebSocket] No token available, redirecting to login');
            stopStream();
            window.location.href = '/';
            return;
        }

        // Start heartbeat
        startHeartbeat();
    }

    function handleMessage(event: MessageEvent) {
        console.log('[WebSocket] Message received:', event.data);
        try {
            const data: WebSocketMessage = JSON.parse(event.data);
            processMessage(data);
        } catch {
            // Ignore parse errors
        }
    }

    function handleError(event: Event) {
        console.error('[WebSocket] Error:', event);
        error.value = 'WebSocket connection error';
        connectionStatus.value = 'disconnected';
        onConnectionChange?.(false);
    }

    function handleClose(event: CloseEvent) {
        console.log('[WebSocket] Closed:', event.code, event.reason);
        isConnected.value = false;
        connectionStatus.value = 'disconnected';
        onConnectionChange?.(false);

        if (heartbeatTimer) {
            clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }

        // Attempt reconnection if not intentionally closed
        if (websocketConfig.reconnect) {
            scheduleReconnect();
        }
    }

    function processMessage(data: WebSocketMessage) {
        // Handle different message types from the LaraClaw server
        switch (data.type) {
            case 'auth_required':
                // Server requires authentication
                console.log('[WebSocket] Authentication required');
                break;

            case 'auth_success':
                // Authentication successful
                console.log('[WebSocket] Authentication successful');
                isAuthenticated.value = true;
                onConnectionChange?.(true);
                
                // Send session subscription
                if (sessionKey.value || friendlyId.value) {
                    send({
                        type: 'subscribe',
                        sessionKey: sessionKey.value,
                        friendlyId: friendlyId.value,
                    });
                }
                
                // Refresh history on connect
                onHistoryRefresh?.();
                break;

            case 'auth_failed':
                // Authentication failed - clear token and redirect to login
                console.log('[WebSocket] Authentication failed');
                authStore.clearToken();
                stopStream();
                window.location.href = '/';
                break;

            case 'welcome':
            case 'connected':
                // Server welcome message
                break;

            case 'response':
                // Agent response from the server
                processAgentResponse(data);
                break;

            case 'chat':
            case 'agent':
                processChatPayload(data);
                break;

            case 'chat.history':
                onHistoryRefresh?.();
                break;

            case 'ping':
                // Respond to ping
                send({ type: 'pong' });
                break;

            case 'pong':
                // Heartbeat response
                break;

            case 'error':
                error.value = data.message || 'Server error';
                onStateChange?.('error');
                break;

            case 'status':
                // Status update from server
                break;

            default:
                // Try to process as chat payload
                if (data.event === 'chat' || data.event === 'agent') {
                    processChatPayload(data);
                }
        }
    }

    function processAgentResponse(data: WebSocketMessage) {
        // Handle the 'response' type from LaraClaw server
        const message = data.message || '';
        const agentData = (data.data || {}) as Record<string, unknown>;
        
        // If we received a conversation_id, notify the parent
        if (agentData.conversation_id && typeof agentData.conversation_id === 'string') {
            onConversationId?.(agentData.conversation_id);
        }
        
        // Create a GatewayMessage from the response
        const gatewayMessage: GatewayMessage = {
            role: 'assistant',
            content: [{ type: 'text', text: message }],
            // Include agent metadata
            __agentId: agentData.agent_id as string | undefined,
            __agentName: agentData.agent_name as string | undefined,
            __provider: agentData.provider as string | undefined,
            __model: agentData.model as string | undefined,
        };
        
        onMessage?.(gatewayMessage);
        onStateChange?.('final');
    }

    function processChatPayload(data: WebSocketMessage) {
        const payloads: (StreamChatPayload | null)[] = [];

        if (data.type === 'chat' || data.event === 'chat') {
            payloads.push(data.payload as StreamChatPayload | null);
        } else if (data.type === 'agent' || data.event === 'agent') {
            payloads.push(...extractChatPayloadsFromAgentPayload(data.payload));
        }

        for (const payload of payloads) {
            if (!payload) continue;

            const runId = typeof payload.runId === 'string' ? payload.runId : '';
            const state = typeof payload.state === 'string' ? payload.state : '';

            if (state === 'delta' || state === 'final' || state === 'error' || state === 'aborted') {
                onStateChange?.(state as 'delta' | 'final' | 'error' | 'aborted', runId);
            }

            if (payload.message) {
                onMessage?.(payload.message);
            }
        }
    }

    function extractChatPayloadsFromAgentPayload(payload: unknown): (StreamChatPayload | null)[] {
        if (!payload || typeof payload !== 'object') return [];

        const value = payload as Record<string, unknown>;
        const runId = normalizeString(value.runId);
        const sessionKey = normalizeString(value.sessionKey);
        const stream = normalizeString(value.stream);
        const seq = typeof value.seq === 'number' && Number.isFinite(value.seq) ? value.seq : undefined;
        const data =
            value.data && typeof value.data === 'object' ? (value.data as Record<string, unknown>) : null;

        if (stream === 'assistant') {
            const text = normalizeString(data?.text) || normalizeString(data?.delta) || '';
            if (!text) return [];
            return [
                {
                    runId,
                    sessionKey,
                    state: 'delta',
                    seq,
                    message: {
                        role: 'assistant',
                        content: [{ type: 'text', text }],
                    },
                },
            ];
        }

        if (stream === 'thinking') {
            const thinking = normalizeString(data?.thinking) || normalizeString(data?.text) || '';
            if (!thinking) return [];
            return [
                {
                    runId,
                    sessionKey,
                    state: 'delta',
                    seq,
                    message: {
                        role: 'assistant',
                        content: [{ type: 'thinking', thinking }],
                    },
                },
            ];
        }

        if (stream === 'lifecycle') {
            const phase = normalizeString(data?.phase);
            if (phase === 'end') {
                return [{ runId, sessionKey, state: 'final', seq }];
            }
            return [];
        }

        if (stream.includes('tool')) {
            const toolCallId =
                normalizeString(data?.toolCallId) ||
                normalizeString(data?.id) ||
                normalizeString(data?.callId);
            const toolName = normalizeString(data?.toolName) || normalizeString(data?.name);

            if (stream.includes('call')) {
                const partialJson = normalizeString(data?.partialJson);
                const input =
                    data && typeof data.input === 'object'
                        ? (data.input as Record<string, unknown>)
                        : data && typeof data.arguments === 'object'
                          ? (data.arguments as Record<string, unknown>)
                          : undefined;

                return [
                    {
                        runId,
                        sessionKey,
                        state: 'delta',
                        seq,
                        message: {
                            role: 'assistant',
                            content: [
                                {
                                    type: 'toolCall',
                                    id: toolCallId || undefined,
                                    name: toolName || undefined,
                                    partialJson: partialJson || undefined,
                                    arguments: input,
                                },
                            ],
                        },
                    },
                ];
            }

            if (stream.includes('result') || stream.includes('output')) {
                const output = data?.output;
                const errorText = normalizeString(data?.error);
                return [
                    {
                        runId,
                        sessionKey,
                        state: 'delta',
                        seq,
                        message: {
                            role: 'toolResult',
                            toolCallId: toolCallId || undefined,
                            toolName: toolName || undefined,
                            details:
                                output && typeof output === 'object'
                                    ? (output as Record<string, unknown>)
                                    : undefined,
                            isError: Boolean(errorText),
                            content: [{ type: 'text', text: errorText || (typeof output === 'string' ? output : '') }],
                        },
                    },
                ];
            }
        }

        return [];
    }

    function normalizeString(value: unknown): string {
        return typeof value === 'string' ? value.trim() : '';
    }

    function send(data: unknown) {
        if (websocket && websocket.readyState === WebSocket.OPEN) {
            websocket.send(JSON.stringify(data));
        }
    }

    function startHeartbeat() {
        heartbeatTimer = setInterval(() => {
            if (websocket && websocket.readyState === WebSocket.OPEN) {
                send({ type: 'ping' });
            }
        }, websocketConfig.heartbeatInterval);
    }

    function scheduleReconnect() {
        if (!websocketConfig.reconnect) return;

        connectionStatus.value = 'reconnecting';
        reconnectAttempt++;

        reconnectTimer = setTimeout(() => {
            reconnectTimer = null;
            connect();
        }, currentReconnectInterval);

        // Increase interval for next attempt (with decay)
        currentReconnectInterval = Math.min(
            currentReconnectInterval * websocketConfig.reconnectDecay,
            websocketConfig.maxReconnectInterval,
        );
    }

    // Send a message through the WebSocket
    function sendMessage(message: unknown) {
        send(message);
    }

    // Watch for session changes
    watch(
        [sessionKey, friendlyId],
        () => {
            // If connected, update subscription
            if (isConnected.value && (sessionKey.value || friendlyId.value)) {
                send({
                    type: 'subscribe',
                    sessionKey: sessionKey.value,
                    friendlyId: friendlyId.value,
                });
            }
        },
    );

    // Start connection immediately on mount
    startStream();

    onUnmounted(() => {
        stopStream();
    });

    return {
        isConnected,
        isAuthenticated,
        error,
        connectionStatus,
        startStream,
        stopStream,
        sendMessage,
    };
}

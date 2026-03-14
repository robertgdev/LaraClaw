<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import ChatSidebar from '@/components/chat/ChatSidebar.vue';
import ChatMessageList from '@/components/chat/ChatMessageList.vue';
import ChatComposer from '@/components/chat/ChatComposer.vue';
import { useChatSettingsStore, applyTheme } from '@/stores/chatSettings';
import { useAuthStore } from '@/stores/auth';
import { useChatStream } from '@/composables/useChatStream';
import type { SessionMeta, GatewayMessage, AttachmentFile, FeedbackValue } from '@/types/chat';
import { createOptimisticMessage, readError, deriveFriendlyIdFromKey } from '@/lib/chat-utils';
import { randomUUID } from '@/lib/utils';

const props = defineProps<{ sessionKey?: string }>();

const settingsStore = useChatSettingsStore();
const authStore = useAuthStore();

const sending = ref(false);
const waitingForResponse = ref(false);
const isSidebarCollapsed = ref(false);
const isDark = ref(false);

const currentSessionKey = ref(props.sessionKey || '');
const currentFriendlyId = ref(props.sessionKey || '');
const messages = ref<GatewayMessage[]>([]);
const sessions = ref<SessionMeta[]>([]);

// ── Theme ──────────────────────────────────
function updateThemeIcon() {
    const theme = settingsStore.settings.theme;
    if (theme === 'dark') {
        isDark.value = true;
    } else if (theme === 'light') {
        isDark.value = false;
    } else {
        isDark.value = window.matchMedia('(prefers-color-scheme: dark)').matches;
    }
}

function toggleTheme() {
    const newTheme = isDark.value ? 'light' : 'dark';
    settingsStore.setTheme(newTheme);
    isDark.value = newTheme === 'dark';
}

// ── Lifecycle ─────────────────────────────
onMounted(() => {
    loadSessions();
    applyTheme(settingsStore.settings.theme);
    updateThemeIcon();
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    media.addEventListener('change', updateThemeIcon);
});

// ── Auth helper ────────────────────────────
function authHeaders(): Record<string, string> {
    const token = authStore.token;
    return token ? { Authorization: `Bearer ${token}` } : {};
}

// ── Data loading ───────────────────────────
async function loadSessions() {
    try {
        const res = await fetch('/api/sessions', { headers: authHeaders() });
        if (!res.ok) throw new Error(await readError(res));
        const data = await res.json();
        sessions.value = (data.sessions || []).map((s: any) => ({
            key: s.key || '',
            friendlyId: s.friendlyId || deriveFriendlyIdFromKey(s.key),
            title: s.title,
            derivedTitle: s.derivedTitle,
            label: s.label,
            updatedAt: s.updatedAt,
            lastMessage: s.lastMessage,
        }));
    } catch (err) {
        console.error('[Chat] Failed to load sessions:', err);
    }
}

async function loadHistory() {
    if (!currentSessionKey.value) return;
    try {
        const query = new URLSearchParams({ limit: '200' });
        query.set('sessionKey', currentSessionKey.value);
        query.set('friendlyId', currentFriendlyId.value);
        const res = await fetch(`/api/history?${query.toString()}`, { headers: authHeaders() });
        if (!res.ok) throw new Error(await readError(res));
        const data = await res.json();
        messages.value = data.messages || [];
    } catch (err) {
        console.error('[Chat] Failed to load history:', err);
    }
}

// ── Session key watcher ────────────────────
watch(
    () => props.sessionKey,
    async (newKey) => {
        if (newKey) {
            currentSessionKey.value = newKey;
            currentFriendlyId.value = newKey;
            await loadHistory();
        } else if (!newKey && (currentSessionKey.value || currentFriendlyId.value)) {
            currentSessionKey.value = '';
            currentFriendlyId.value = '';
            messages.value = [];
        }
    },
    { immediate: true },
);

// ── WebSocket stream ───────────────────────
const {
    isConnected: streamConnected,
    connectionStatus,
    sendMessage: sendWsMessage,
} = useChatStream({
    sessionKey: currentSessionKey,
    friendlyId: currentFriendlyId,
    onMessage: (message) => {
        // Only replace an existing message if it shares the SAME streaming run id.
        // Never replace a message that belongs to a different run.
        const runId = message.__streamRunId;
        if (runId) {
            const existingIndex = messages.value.findIndex((m) => m.__streamRunId === runId);
            if (existingIndex >= 0) {
                messages.value[existingIndex] = message;
                return;
            }
        }
        // For non-streaming or first delta: append at the end.
        messages.value.push(message);
    },
    onStateChange: (state) => {
        if (state === 'final' || state === 'error' || state === 'aborted') {
            waitingForResponse.value = false;
            // Reload history from server to get the canonical, correctly-ordered list.
            loadHistory();
        }
    },
    onHistoryRefresh: () => { loadHistory(); },
    onConnectionChange: (connected) => {
        if (connected) console.log('[Chat] WebSocket connected');
    },
    onConversationId: (conversationId) => {
        if (conversationId && currentSessionKey.value !== conversationId) {
            currentSessionKey.value = conversationId;
            currentFriendlyId.value = conversationId;
            loadSessions();
        }
    },
});

// ── Actions ───────────────────────────────
async function handleCreateSession() {
    try {
        sending.value = true;
        const res = await fetch('/api/sessions', {
            method: 'POST',
            headers: { 'content-type': 'application/json', ...authHeaders() },
            body: JSON.stringify({}),
        });
        if (!res.ok) throw new Error(await readError(res));
        const data = await res.json();
        const sessionKey = data.sessionKey || '';
        const friendlyId = data.friendlyId || deriveFriendlyIdFromKey(sessionKey);
        router.visit(`/chat/${friendlyId}`);
    } catch (err) {
        console.error('[Chat] Failed to create session:', err);
    } finally {
        sending.value = false;
    }
}

function handleSendMessage(body: string, attachments: AttachmentFile[]) {
    if (!body.trim() && attachments.length === 0) return;
    sending.value = true;
    waitingForResponse.value = true;
    const { optimisticMessage } = createOptimisticMessage(body, attachments);
    messages.value.push(optimisticMessage);
    sendWsMessage({
        type: 'message_send',
        message: body,
        sessionKey: currentSessionKey.value,
        friendlyId: currentFriendlyId.value,
        thinking: settingsStore.settings.thinkingLevel,
        idempotencyKey: randomUUID(),
        attachments: attachments.map((a) => ({
            mimeType: a.file.type,
            content: a.base64,
        })),
    });
    setTimeout(() => { sending.value = false; }, 100);
}

function handleSelectSession(session: SessionMeta) {
    messages.value = [];
    router.visit(`/chat/${session.friendlyId}`);
}

async function handleDeleteSession(session: SessionMeta) {
    try {
        const res = await fetch(
            `/api/sessions?sessionKey=${encodeURIComponent(session.key)}&friendlyId=${encodeURIComponent(session.friendlyId)}`,
            { method: 'DELETE', headers: authHeaders() },
        );
        if (!res.ok) throw new Error(await readError(res));
        // Remove from local list immediately
        sessions.value = sessions.value.filter((s) => s.key !== session.key);
        if (session.friendlyId === currentFriendlyId.value) {
            router.visit('/chat');
        }
    } catch (err) {
        console.error('[Chat] Failed to delete session:', err);
    }
}

function handleMessageFeedback(messageId: string, feedback: FeedbackValue) {
    sendWsMessage({ type: 'feedback_message', message_id: messageId, feedback });
    const msg = messages.value.find((m) => m.messageId === messageId || m.id === messageId);
    if (msg) msg.feedback = feedback;
}

function handleLogout() {
    authStore.logout();
    router.visit('/');
}

// ── Computed ──────────────────────────────
const currentTitle = computed(() => {
    const session = sessions.value.find((s) => s.friendlyId === currentFriendlyId.value);
    return session?.label || session?.title || session?.derivedTitle || 'New Chat';
});
</script>

<template>
    <div class="h-screen flex overflow-hidden bg-white dark:bg-neutral-950 text-neutral-900 dark:text-neutral-100">

        <!-- ── Sidebar ── -->
        <ChatSidebar
            :sessions="sessions"
            :active-friendly-id="currentFriendlyId"
            :is-collapsed="isSidebarCollapsed"
            :creating-session="sending"
            @create-session="handleCreateSession"
            @select-session="handleSelectSession"
            @delete-session="handleDeleteSession"
            @toggle-collapse="isSidebarCollapsed = !isSidebarCollapsed"
        />

        <!-- ── Main area ── -->
        <div class="flex flex-col flex-1 min-w-0 h-full">

            <!-- ── Chat header ── -->
            <header class="flex-shrink-0 flex items-center gap-3 px-4 h-12 border-b border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-950">
                <!-- Session title -->
                <h1 class="flex-1 min-w-0 text-sm font-medium text-neutral-800 dark:text-neutral-200 truncate">
                    {{ currentTitle }}
                </h1>

                <!-- Connection status badge -->
                <span
                    class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium flex-shrink-0"
                    :class="streamConnected
                        ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                        : connectionStatus === 'reconnecting'
                          ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'
                          : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'"
                >
                    <span
                        class="w-1.5 h-1.5 rounded-full"
                        :class="streamConnected
                            ? 'bg-green-500'
                            : connectionStatus === 'reconnecting'
                              ? 'bg-yellow-500 animate-pulse'
                              : 'bg-red-500'"
                    />
                    {{ streamConnected ? 'Connected' : connectionStatus === 'reconnecting' ? 'Reconnecting…' : 'Disconnected' }}
                </span>

                <!-- Theme toggle -->
                <button
                    type="button"
                    class="flex items-center justify-center w-8 h-8 rounded-lg text-neutral-500 dark:text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800 hover:text-neutral-800 dark:hover:text-neutral-100 transition-colors flex-shrink-0"
                    :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                    @click="toggleTheme"
                >
                    <!-- Sun icon (dark mode active) -->
                    <svg v-if="isDark" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                        <circle cx="12" cy="12" r="5" />
                        <line x1="12" y1="1" x2="12" y2="3" />
                        <line x1="12" y1="21" x2="12" y2="23" />
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" />
                        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" />
                        <line x1="1" y1="12" x2="3" y2="12" />
                        <line x1="21" y1="12" x2="23" y2="12" />
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" />
                        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" />
                    </svg>
                    <!-- Moon icon (light mode active) -->
                    <svg v-else xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                    </svg>
                </button>

                <!-- Logout -->
                <button
                    type="button"
                    class="flex items-center justify-center w-8 h-8 rounded-lg text-neutral-500 dark:text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800 hover:text-neutral-800 dark:hover:text-neutral-100 transition-colors flex-shrink-0"
                    title="Logout"
                    @click="handleLogout"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                        <polyline points="16 17 21 12 16 7" />
                        <line x1="21" y1="12" x2="9" y2="12" />
                    </svg>
                </button>
            </header>

            <!-- ── Messages ── -->
            <ChatMessageList
                :messages="messages"
                :waiting-for-response="waitingForResponse"
                @feedback="handleMessageFeedback"
            />

            <!-- ── Composer ── -->
            <ChatComposer
                :is-loading="sending"
                :disabled="sending"
                @submit="handleSendMessage"
            />
        </div>
    </div>
</template>

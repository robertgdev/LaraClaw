<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import ChatSidebar from '@/components/chat/ChatSidebar.vue';
import ChatMessageList from '@/components/chat/ChatMessageList.vue';
import ChatComposer from '@/components/chat/ChatComposer.vue';
import { useChatSettingsStore, applyTheme } from '@/stores/chatSettings';
import { useAuthStore } from '@/stores/auth';
import { useChatStream } from '@/composables/useChatStream';
import type { SessionMeta, GatewayMessage, AttachmentFile } from '@/types/chat';
import { createOptimisticMessage, readError, deriveFriendlyIdFromKey } from '@/lib/chat-utils';
import { randomUUID } from '@/lib/utils';

const props = defineProps<{
    sessionKey?: string;
}>();

const settingsStore = useChatSettingsStore();
const authStore = useAuthStore();

const sending = ref(false);
const waitingForResponse = ref(false);
const isMobile = ref(false);
const isSidebarCollapsed = ref(false);

// Track current resolved theme for icon display
const isDark = ref(false);

// Theme functions
function updateThemeIcon() {
    const theme = settingsStore.settings.theme;
    if (theme === 'dark') {
        isDark.value = true;
    } else if (theme === 'light') {
        isDark.value = false;
    } else {
        // System theme
        isDark.value = window.matchMedia('(prefers-color-scheme: dark)').matches;
    }
}

function toggleTheme() {
    // Simple toggle between light and dark
    const newTheme = isDark.value ? 'light' : 'dark';
    console.log('toggleTheme called, current isDark:', isDark.value, 'newTheme:', newTheme);
    settingsStore.setTheme(newTheme);
    // Update isDark based on the new theme
    isDark.value = newTheme === 'dark';
    console.log('After toggle, isDark:', isDark.value, 'document.documentElement.classList:', document.documentElement.classList.toString());
}

// Current session
const currentSessionKey = ref(props.sessionKey || '');
const currentFriendlyId = ref(props.sessionKey || '');

// Messages for current session
const messages = ref<GatewayMessage[]>([]);

// Sessions list
const sessions = ref<SessionMeta[]>([]);

// Check for mobile
onMounted(() => {
    isMobile.value = window.innerWidth < 768;
    window.addEventListener('resize', handleResize);
    loadSessions();
    
    // Initialize theme
    applyTheme(settingsStore.settings.theme);
    updateThemeIcon();
    
    // Listen for system theme changes
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    media.addEventListener('change', updateThemeIcon);
});

onUnmounted(() => {
    window.removeEventListener('resize', handleResize);
});

function handleResize() {
    isMobile.value = window.innerWidth < 768;
}

// Load sessions
async function loadSessions() {
    try {
        const res = await fetch('/api/sessions');
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
        console.error('Failed to load sessions:', err);
    }
}

// Load history
async function loadHistory() {
    if (!currentSessionKey.value) return;
    
    try {
        const query = new URLSearchParams({ limit: '200' });
        query.set('sessionKey', currentSessionKey.value);
        query.set('friendlyId', currentFriendlyId.value);

        const res = await fetch(`/api/history?${query.toString()}`);
        if (!res.ok) throw new Error(await readError(res));
        const data = await res.json();
        messages.value = data.messages || [];
    } catch (err) {
        console.error('Failed to load history:', err);
    }
}

// Watch for session key changes
watch(
    () => props.sessionKey,
    async (newKey) => {
        // Always update and load history when the session key changes
        if (newKey) {
            currentSessionKey.value = newKey;
            currentFriendlyId.value = newKey;
            await loadHistory();
        } else if (!newKey && (currentSessionKey.value || currentFriendlyId.value)) {
            // Navigating to new chat - clear state
            currentSessionKey.value = '';
            currentFriendlyId.value = '';
            messages.value = [];
        }
    },
    { immediate: true },
);

// Stream connection (WebSocket)
const { isConnected: streamConnected, connectionStatus, error: streamError, sendMessage: sendWsMessage } = useChatStream({
    sessionKey: currentSessionKey,
    friendlyId: currentFriendlyId,
    onMessage: (message) => {
        // Update messages with streaming message
        const existingIndex = messages.value.findIndex(
            (m) => m.id === message.id || m.__streamRunId === message.__streamRunId,
        );
        if (existingIndex >= 0) {
            messages.value[existingIndex] = message;
        } else {
            messages.value.push(message);
        }
    },
    onStateChange: (state) => {
        if (state === 'final' || state === 'error' || state === 'aborted') {
            waitingForResponse.value = false;
            loadHistory();
        }
    },
    onHistoryRefresh: () => {
        loadHistory();
    },
    onConnectionChange: (connected) => {
        if (connected) {
            console.log('WebSocket connected to LaraClaw server');
        }
    },
    onConversationId: (conversationId) => {
        // Update session key when we receive a conversation_id from the server
        // Always update if we don't have one, or if it's different
        if (conversationId && currentSessionKey.value !== conversationId) {
            currentSessionKey.value = conversationId;
            currentFriendlyId.value = conversationId;
            // Refresh sessions list to show the new/updated session
            loadSessions();
        }
    },
});

// Handle create session
async function handleCreateSession() {
    try {
                sending.value = true;
                const res = await fetch('/api/sessions', {
                    method: 'POST',
                    headers: { 'content-type': 'application/json' },
                    body: JSON.stringify({}),
                });
                if (!res.ok) throw new Error(await readError(res));
                const data = await res.json();
                const sessionKey = data.sessionKey || '';
                const friendlyId = data.friendlyId || deriveFriendlyIdFromKey(sessionKey);
                
                // Navigate to new session
                router.visit(`/chat/${friendlyId}`);
            } catch (err) {
                console.error('Failed to create session:', err);
            } finally {
                sending.value = false;
            }
}

// Handle send message
function handleSendMessage(body: string, attachments: AttachmentFile[]) {
    if (!body.trim() && attachments.length === 0) return;
    
    sending.value = true;
    waitingForResponse.value = true;
    
    // Create optimistic message
    const { clientId, optimisticId, optimisticMessage } = createOptimisticMessage(
        body,
        attachments,
    );
    messages.value.push(optimisticMessage);
    
    // Send via WebSocket using the expected format
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
    
    // Reset sending state after a short delay (actual response will come via WebSocket)
    setTimeout(() => {
        sending.value = false;
    }, 100);
}

// Handle select session
function handleSelectSession(session: SessionMeta) {
    // Clear messages immediately for better UX
    messages.value = [];
    // Navigate to the session - the watch will handle loading history
    if (isMobile.value) {
        isSidebarCollapsed.value = true;
    }
    router.visit(`/chat/${session.friendlyId}`);
}

// Handle delete session
async function handleDeleteSession(session: SessionMeta) {
    try {
        const res = await fetch(`/api/sessions?sessionKey=${session.key}&friendlyId=${session.friendlyId}`, {
            method: 'DELETE',
        });
        if (!res.ok) throw new Error(await readError(res));
        await loadSessions();
        if (session.friendlyId === currentFriendlyId.value) {
            router.visit('/chat');
        }
    } catch (err) {
        console.error('Failed to delete session:', err);
    }
}

// Handle rename session
async function handleRenameSession(session: SessionMeta, newTitle: string) {
    try {
        const res = await fetch('/api/sessions/rename', {
            method: 'POST',
            headers: { 'content-type': 'application/json' },
            body: JSON.stringify({ sessionKey: session.key, title: newTitle }),
        });
        if (!res.ok) throw new Error(await readError(res));
        await loadSessions();
    } catch (err) {
        console.error('Failed to rename session:', err);
    }
}

// Handle toggle sidebar
function handleToggleSidebar() {
    isSidebarCollapsed.value = !isSidebarCollapsed.value;
}

// Handle logout
function handleLogout() {
    authStore.logout();
    router.visit('/');
}

// Current title
const currentTitle = computed(() => {
    const session = sessions.value.find(
        (s) => s.friendlyId === currentFriendlyId.value,
    );
    return session?.label || session?.title || session?.derivedTitle || 'New Chat';
});

// Is new chat
const isNewChat = computed(() => !props.sessionKey);
</script>

<template>
    <div class="h-screen bg-surface text-primary-900 dark:text-primary-100">
        <div
            :class="
                isMobile
                    ? 'relative h-full overflow-hidden'
                    : 'grid grid-cols-[auto_1fr] h-full overflow-hidden'
            "
        >
            <!-- Sidebar -->
            <ChatSidebar
                :sessions="sessions"
                :active-friendly-id="currentFriendlyId"
                :is-collapsed="isMobile ? false : isSidebarCollapsed"
                :creating-session="sending"
                @create-session="handleCreateSession"
                @select-session="handleSelectSession"
                @delete-session="handleDeleteSession"
                @rename-session="handleRenameSession"
                @toggle-collapse="handleToggleSidebar"
            />

            <!-- Main content -->
            <main class="flex flex-col h-full min-h-0 bg-surface dark:bg-primary-950">
                <!-- Header -->
                <header class="flex items-center h-12 px-4 border-b border-primary-200 dark:border-primary-700 dark:bg-primary-950">
                    <button
                        v-if="isMobile"
                        type="button"
                        class="p-2 hover:bg-primary-100 dark:hover:bg-primary-800 rounded-lg"
                        @click="isSidebarCollapsed = false"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M3 12h18M3 12h18M3 12h18M3 6h18M3 6h18" />
                        </svg>
                    </button>
                    <h1 class="text-lg font-medium text-primary-900 dark:text-primary-100 truncate flex-1">
                        {{ currentTitle }}
                    </h1>
                    <!-- Connection status indicator -->
                    <div class="flex items-center gap-2 text-xs">
                        <span
                            :class="[
                                'inline-flex items-center gap-1 px-2 py-0.5 rounded-full',
                                streamConnected 
                                    ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' 
                                    : connectionStatus === 'reconnecting'
                                      ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'
                                      : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                            ]"
                        >
                            <span
                                :class="[
                                    'w-2 h-2 rounded-full',
                                    streamConnected 
                                        ? 'bg-green-500' 
                                        : connectionStatus === 'reconnecting'
                                          ? 'bg-yellow-500 animate-pulse'
                                          : 'bg-red-500'
                                ]"
                            />
                            {{ streamConnected ? 'Connected' : connectionStatus === 'reconnecting' ? 'Reconnecting...' : 'Disconnected' }}
                        </span>
                    </div>
                    <!-- Theme toggle button -->
                    <button
                        type="button"
                        class="ml-2 p-2 hover:bg-primary-100 dark:hover:bg-primary-800 rounded-lg text-primary-600 dark:text-primary-300 hover:text-primary-900 dark:hover:text-primary-100"
                        :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                        @click="toggleTheme"
                    >
                        <!-- Sun icon (shown in dark mode) -->
                        <svg v-if="isDark" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
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
                        <!-- Moon icon (shown in light mode) -->
                        <svg v-else xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                        </svg>
                    </button>
                    <!-- Logout button -->
                    <button
                        type="button"
                        class="ml-2 p-2 hover:bg-primary-100 dark:hover:bg-primary-800 rounded-lg text-primary-600 dark:text-primary-300 hover:text-primary-900 dark:hover:text-primary-100"
                        title="Logout"
                        @click="handleLogout"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                            <polyline points="16 17 21 12 16 7" />
                            <line x1="21" y1="12" x2="9" y2="12" />
                        </svg>
                    </button>
                </header>

                <!-- Messages -->
                <ChatMessageList
                    :messages="messages"
                    :loading="false"
                    :empty="messages.length === 0"
                    :waiting-for-response="waitingForResponse"
                    :session-key="currentSessionKey"
                />

                <!-- Composer -->
                <ChatComposer
                    :is-loading="sending"
                    :disabled="sending"
                    @submit="handleSendMessage"
                />
            </main>
        </div>
    </div>
</template>

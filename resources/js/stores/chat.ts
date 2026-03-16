import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { normalizeSessions, readError, deriveFriendlyIdFromKey } from '@/lib/chat-utils';
import { randomUUID } from '@/lib/utils';
import type { GatewayMessage, SessionMeta, HistoryResponse } from '@/types/chat';

export const useChatStore = defineStore('chat', () => {
    // State
    const sessions = ref<SessionMeta[]>([]);
    const currentSessionKey = ref<string>('');
    const currentFriendlyId = ref<string>('');
    const messages = ref<Map<string, GatewayMessage[]>>(new Map());
    const isLoading = ref(false);
    const isSending = ref(false);
    const error = ref<string | null>(null);
    const isSidebarCollapsed = ref(false);

    // Getters
    const currentMessages = computed(() => {
        const key = currentSessionKey.value || currentFriendlyId.value;
        return messages.value.get(key) || [];
    });

    const currentSession = computed(() => {
        return sessions.value.find(
            (s) => s.key === currentSessionKey.value || s.friendlyId === currentFriendlyId.value,
        );
    });

    // Actions
    async function fetchSessions() {
        try {
            isLoading.value = true;
            error.value = null;

            const res = await fetch('/api/sessions');
            if (!res.ok) throw new Error(await readError(res));

            const data = await res.json();
            sessions.value = normalizeSessions(data.sessions);
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Failed to fetch sessions';
            throw err;
        } finally {
            isLoading.value = false;
        }
    }

    async function fetchHistory(sessionKey: string, friendlyId: string) {
        try {
            isLoading.value = true;
            error.value = null;

            const query = new URLSearchParams({ limit: '200' });
            if (sessionKey) query.set('sessionKey', sessionKey);
            if (friendlyId) query.set('friendlyId', friendlyId);

            const res = await fetch(`/api/history?${query.toString()}`);
            if (!res.ok) throw new Error(await readError(res));

            const data: HistoryResponse = await res.json();
            const key = sessionKey || friendlyId;
            messages.value.set(key, data.messages);

            return data;
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Failed to fetch history';
            throw err;
        } finally {
            isLoading.value = false;
        }
    }

    async function createSession() {
        try {
            isLoading.value = true;
            error.value = null;

            const res = await fetch('/api/sessions', {
                method: 'POST',
                headers: { 'content-type': 'application/json' },
                body: JSON.stringify({}),
            });

            if (!res.ok) throw new Error(await readError(res));

            const data = await res.json();
            const sessionKey = typeof data.sessionKey === 'string' ? data.sessionKey : '';
            const friendlyId =
                typeof data.friendlyId === 'string' && data.friendlyId.trim().length > 0
                    ? data.friendlyId.trim()
                    : deriveFriendlyIdFromKey(sessionKey);

            // Refresh sessions list
            await fetchSessions();

            return { sessionKey, friendlyId };
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Failed to create session';
            throw err;
        } finally {
            isLoading.value = false;
        }
    }

    async function sendMessage(
        sessionKey: string,
        friendlyId: string,
        message: string,
        thinkingLevel: string = 'medium',
        attachments?: { mimeType: string; content: string }[],
    ) {
        try {
            isSending.value = true;
            error.value = null;

            const res = await fetch('/api/send', {
                method: 'POST',
                headers: { 'content-type': 'application/json' },
                body: JSON.stringify({
                    sessionKey,
                    friendlyId,
                    message,
                    thinking: thinkingLevel,
                    idempotencyKey: randomUUID(),
                    attachments,
                }),
            });

            if (!res.ok) throw new Error(await readError(res));

            const data = await res.json();
            return data;
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Failed to send message';
            throw err;
        } finally {
            isSending.value = false;
        }
    }

    async function deleteSession(sessionKey: string, friendlyId: string) {
        try {
            isLoading.value = true;
            error.value = null;

            const res = await fetch(`/api/sessions?sessionKey=${sessionKey}&friendlyId=${friendlyId}`, {
                method: 'DELETE',
            });

            if (!res.ok) throw new Error(await readError(res));

            // Remove from local state
            sessions.value = sessions.value.filter(
                (s) => s.key !== sessionKey && s.friendlyId !== friendlyId,
            );

            // Remove messages
            messages.value.delete(sessionKey);
            messages.value.delete(friendlyId);
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Failed to delete session';
            throw err;
        } finally {
            isLoading.value = false;
        }
    }

    async function renameSession(sessionKey: string, newTitle: string) {
        try {
            isLoading.value = true;
            error.value = null;

            const res = await fetch('/api/sessions/rename', {
                method: 'POST',
                headers: { 'content-type': 'application/json' },
                body: JSON.stringify({ sessionKey, title: newTitle }),
            });

            if (!res.ok) throw new Error(await readError(res));

            // Update local state
            const session = sessions.value.find((s) => s.key === sessionKey);
            if (session) {
                session.label = newTitle;
            }
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Failed to rename session';
            throw err;
        } finally {
            isLoading.value = false;
        }
    }

    // Optimistic message handling
    function appendMessage(sessionKey: string, message: GatewayMessage) {
        const existing = messages.value.get(sessionKey) || [];
        messages.value.set(sessionKey, [...existing, message]);
    }

    function updateMessage(
        sessionKey: string,
        clientId: string,
        updater: (msg: GatewayMessage) => GatewayMessage,
    ) {
        const existing = messages.value.get(sessionKey);
        if (!existing) return;

        const updated = existing.map((msg) => {
            if (msg.clientId === clientId || msg.__optimisticId === clientId) {
                return updater(msg);
            }
            return msg;
        });

        messages.value.set(sessionKey, updated);
    }

    function removeMessage(sessionKey: string, clientId: string) {
        const existing = messages.value.get(sessionKey);
        if (!existing) return;

        messages.value.set(
            sessionKey,
            existing.filter((msg) => msg.clientId !== clientId && msg.__optimisticId !== clientId),
        );
    }

    // UI state
    function setCurrentSession(sessionKey: string, friendlyId: string) {
        currentSessionKey.value = sessionKey;
        currentFriendlyId.value = friendlyId;
    }

    function toggleSidebar() {
        isSidebarCollapsed.value = !isSidebarCollapsed.value;
    }

    function setSidebarCollapsed(collapsed: boolean) {
        isSidebarCollapsed.value = collapsed;
    }

    return {
        // State
        sessions,
        currentSessionKey,
        currentFriendlyId,
        messages,
        isLoading,
        isSending,
        error,
        isSidebarCollapsed,

        // Getters
        currentMessages,
        currentSession,

        // Actions
        fetchSessions,
        fetchHistory,
        createSession,
        sendMessage,
        deleteSession,
        renameSession,
        appendMessage,
        updateMessage,
        removeMessage,
        setCurrentSession,
        toggleSidebar,
        setSidebarCollapsed,
    };
});

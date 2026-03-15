<script setup lang="ts">
import { ref, type PropType } from 'vue';
import type { SessionMeta, FeedbackValue } from '@/types/chat';
import { textFromMessage } from '@/lib/chat-utils';
import FeedbackButtons from './FeedbackButtons.vue';

const props = defineProps({
    sessions: {
        type: Array as PropType<SessionMeta[]>,
        required: true,
    },
    activeFriendlyId: {
        type: String,
        default: '',
    },
    isCollapsed: {
        type: Boolean,
        default: false,
    },
    creatingSession: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits<{
    createSession: [];
    selectSession: [session: SessionMeta];
    toggleCollapse: [];
    deleteSession: [session: SessionMeta];
    conversationFeedback: [conversationId: string, feedback: FeedbackValue];
}>();

function getSessionTitle(session: SessionMeta): string {
    return session.label || session.title || session.derivedTitle || session.friendlyId;
}

function getLastMessagePreview(session: SessionMeta): string {
    if (!session.lastMessage) return '';
    const text = textFromMessage(session.lastMessage);
    return text.length > 52 ? text.slice(0, 52) + '…' : text;
}

function handleDeleteClick(session: SessionMeta, event: MouseEvent) {
    event.stopPropagation();
    emit('deleteSession', session);
}

function handleConversationFeedback(session: SessionMeta, feedback: FeedbackValue) {
    emit('conversationFeedback', session.friendlyId, feedback);
}
</script>

<template>
    <aside
        class="flex flex-col h-full border-r border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-900 transition-all duration-200 overflow-hidden"
        :style="isCollapsed ? 'width: 48px; min-width: 48px;' : 'width: 260px; min-width: 260px;'"
    >
        <!-- Header row: logo + collapse toggle -->
        <div class="flex items-center h-12 px-2 gap-1 flex-shrink-0">
            <!-- Logo (shown when expanded) -->
            <button
                v-if="!isCollapsed"
                type="button"
                class="flex items-center gap-2 flex-1 min-w-0 px-1 py-1.5 rounded-lg hover:bg-neutral-200 dark:hover:bg-neutral-800 transition-colors text-left"
                @click="$emit('createSession')"
                :disabled="creatingSession"
            >
                <img src="/img/lc_logo1.png" alt="LaraClaw" class="w-6 h-6 object-contain flex-shrink-0" />
                <span class="text-sm font-semibold text-neutral-800 dark:text-neutral-100 truncate">LaraClaw</span>
            </button>
            <!-- When collapsed just show logo icon -->
            <button
                v-else
                type="button"
                class="flex items-center justify-center w-8 h-8 rounded-lg hover:bg-neutral-200 dark:hover:bg-neutral-800 transition-colors mx-auto"
                @click="$emit('createSession')"
                :disabled="creatingSession"
                title="New chat"
            >
                <img src="/img/lc_logo1.png" alt="LaraClaw" class="w-5 h-5 object-contain" />
            </button>

            <!-- Collapse toggle button (only when expanded) -->
            <button
                v-if="!isCollapsed"
                type="button"
                class="flex-shrink-0 flex items-center justify-center w-7 h-7 rounded-md text-neutral-500 dark:text-neutral-400 hover:bg-neutral-200 dark:hover:bg-neutral-800 hover:text-neutral-800 dark:hover:text-neutral-100 transition-colors"
                title="Collapse sidebar"
                @click="$emit('toggleCollapse')"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="11 17 6 12 11 7" />
                    <polyline points="18 17 13 12 18 7" />
                </svg>
            </button>
        </div>

        <!-- Expand button (only when collapsed) -->
        <div v-if="isCollapsed" class="flex justify-center mt-1 mb-2">
            <button
                type="button"
                class="flex items-center justify-center w-8 h-8 rounded-md text-neutral-500 dark:text-neutral-400 hover:bg-neutral-200 dark:hover:bg-neutral-800 hover:text-neutral-800 dark:hover:text-neutral-100 transition-colors"
                title="Expand sidebar"
                @click="$emit('toggleCollapse')"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="13 17 18 12 13 7" />
                    <polyline points="6 17 11 12 6 7" />
                </svg>
            </button>
        </div>

        <!-- New Chat button (expanded) -->
        <div v-if="!isCollapsed" class="px-2 mb-2 flex-shrink-0">
            <button
                type="button"
                :disabled="creatingSession"
                class="flex items-center gap-2 w-full px-3 py-2 rounded-lg text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-200 dark:hover:bg-neutral-800 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                @click="$emit('createSession')"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19" />
                    <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
                <span>New Chat</span>
            </button>
        </div>

        <!-- Session List (only when expanded) -->
        <div
            v-if="!isCollapsed"
            class="flex-1 min-h-0 overflow-y-auto px-2 pb-2"
            style="scrollbar-width: thin; scrollbar-color: rgba(156,163,175,0.4) transparent;"
        >
            <div
                v-if="sessions.length === 0"
                class="text-xs text-neutral-400 dark:text-neutral-500 text-center mt-6 px-2"
            >
                No chat sessions yet
            </div>

            <div
                v-for="session in sessions"
                :key="session.key"
                class="group relative flex items-start gap-2 px-3 py-2.5 rounded-lg cursor-pointer mb-0.5 transition-colors"
                :class="session.friendlyId === activeFriendlyId
                    ? 'bg-neutral-200 dark:bg-neutral-700 text-neutral-900 dark:text-neutral-50'
                    : 'text-neutral-700 dark:text-neutral-300 hover:bg-neutral-200 dark:hover:bg-neutral-800'"
                @click="$emit('selectSession', session)"
            >
                <!-- Session info -->
                <div class="flex-1 min-w-0 pr-1">
                    <div class="text-sm font-medium truncate leading-5">
                        {{ getSessionTitle(session) }}
                    </div>
                    <div v-if="getLastMessagePreview(session)" class="text-xs text-neutral-500 dark:text-neutral-400 truncate mt-0.5 leading-4">
                        {{ getLastMessagePreview(session) }}
                    </div>
                </div>

                <!-- Hover actions: feedback + delete
                     Show when hovered OR when there's an existing feedback value (to keep it highlighted).
                     The FeedbackButtons component itself highlights the selected button. -->
                <div
                    class="flex-shrink-0 flex items-center gap-0.5 mt-0.5 transition-opacity"
                    :class="session.feedback !== null && session.feedback !== undefined
                        ? 'opacity-100'
                        : 'opacity-0 group-hover:opacity-100'"
                    @click.stop
                >
                    <!-- Feedback buttons -->
                    <FeedbackButtons
                        :feedback="session.feedback"
                        size="sm"
                        @feedback="(fb: FeedbackValue) => handleConversationFeedback(session, fb)"
                    />

                    <!-- Vertical divider -->
                    <span class="w-px h-3.5 bg-neutral-300 dark:bg-neutral-600 mx-0.5"></span>

                    <!-- Delete button -->
                    <button
                        type="button"
                        class="flex items-center justify-center w-5 h-5 rounded text-red-500 hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors"
                        title="Delete session"
                        @click="handleDeleteClick(session, $event)"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="3 6 5 6 21 6" />
                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                            <path d="M10 11v6M14 11v6" />
                            <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </aside>
</template>

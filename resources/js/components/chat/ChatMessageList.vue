<script setup lang="ts">
import { ref, computed, watch, onMounted, nextTick, type PropType } from 'vue';
import type { GatewayMessage, FeedbackValue } from '@/types/chat';
import { getToolCallsFromMessage } from '@/lib/chat-utils';
import { useChatSettingsStore } from '@/stores/chatSettings';
import MessageItem from './MessageItem.vue';

const props = defineProps({
    messages: {
        type: Array as PropType<GatewayMessage[]>,
        required: true,
    },
    waitingForResponse: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits<{
    feedback: [messageId: string, feedback: FeedbackValue];
}>();

const settingsStore = useChatSettingsStore();
const scrollRef = ref<HTMLDivElement | null>(null);
const userIsScrolling = ref(false);

// Linked tool call IDs (so we can skip toolResult messages that are paired)
const linkedToolCallIds = computed(() => {
    const ids = new Set<string>();
    for (const msg of props.messages) {
        if (msg.role !== 'assistant') continue;
        for (const tc of getToolCallsFromMessage(msg)) {
            const id = typeof tc.id === 'string' ? tc.id.trim() : '';
            if (id) ids.add(id);
        }
    }
    return ids;
});

// Messages to display — skip paired toolResult entries when showToolMessages is enabled
const displayMessages = computed(() => {
    return props.messages.filter((msg) => {
        if (msg.role !== 'toolResult') return true;
        if (!settingsStore.settings.showToolMessages) return true;
        const toolCallId = typeof msg.toolCallId === 'string' ? msg.toolCallId.trim() : '';
        if (!toolCallId) return true;
        return !linkedToolCallIds.value.has(toolCallId);
    });
});

// Tool results map for assistant messages
const toolResultsByCallId = computed(() => {
    const map = new Map<string, GatewayMessage>();
    for (const msg of props.messages) {
        if (msg.role !== 'toolResult') continue;
        const toolCallId = msg.toolCallId;
        if (typeof toolCallId === 'string' && toolCallId.trim()) {
            map.set(toolCallId, msg);
        }
    }
    return map;
});

// Typing indicator: show when waiting and no assistant reply yet after last user message
const lastUserIndex = computed(() => {
    let idx = -1;
    for (let i = 0; i < displayMessages.value.length; i++) {
        if (displayMessages.value[i].role === 'user') idx = i;
    }
    return idx;
});

const lastAssistantIndex = computed(() => {
    let idx = -1;
    for (let i = 0; i < displayMessages.value.length; i++) {
        if (displayMessages.value[i].role !== 'user') idx = i;
    }
    return idx;
});

const showTypingIndicator = computed(() => {
    return (
        props.waitingForResponse &&
        (lastUserIndex.value === -1 ||
            lastAssistantIndex.value === -1 ||
            lastAssistantIndex.value < lastUserIndex.value)
    );
});

function isScrolledToBottom(): boolean {
    if (!scrollRef.value) return true;
    const { scrollTop, scrollHeight, clientHeight } = scrollRef.value;
    return scrollHeight - scrollTop - clientHeight < 60;
}

function scrollToBottom() {
    nextTick(() => {
        if (scrollRef.value) {
            scrollRef.value.scrollTop = scrollRef.value.scrollHeight;
        }
    });
}

function handleScroll() {
    if (!scrollRef.value) return;
    userIsScrolling.value = !isScrolledToBottom();
}

watch(
    () => props.messages.length,
    () => {
        if (!userIsScrolling.value) scrollToBottom();
    },
);

watch(
    () => props.waitingForResponse,
    (val) => {
        if (val && !userIsScrolling.value) scrollToBottom();
    },
);

onMounted(() => {
    scrollToBottom();
});
</script>

<template>
    <!-- scrollbar-thin uses Tailwind scrollbar plugin; fallback handled via CSS below -->
    <div
        ref="scrollRef"
        class="flex-1 min-h-0 overflow-y-auto overflow-x-hidden"
        style="scrollbar-width: thin; scrollbar-color: rgba(156,163,175,0.5) transparent;"
        @scroll="handleScroll"
    >
        <div class="pt-6 pb-2 px-4">
            <div class="mx-auto w-full max-w-2xl">

                <!-- Empty state -->
                <div v-if="displayMessages.length === 0 && !waitingForResponse" class="flex flex-col items-center justify-center h-48 text-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="text-neutral-300 dark:text-neutral-600 mb-3">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                    </svg>
                    <p class="text-neutral-500 dark:text-neutral-400 text-sm">Start a conversation</p>
                    <p class="text-neutral-400 dark:text-neutral-500 text-xs mt-1">Type a message below to begin</p>
                </div>

                <!-- Message list -->
                <div v-else class="flex flex-col gap-5">
                    <MessageItem
                        v-for="(msg, index) in displayMessages"
                        :key="msg.__optimisticId || msg.id || index"
                        :message="msg"
                        :tool-results-by-call-id="
                            msg.role === 'assistant' && getToolCallsFromMessage(msg).length > 0
                                ? toolResultsByCallId
                                : undefined
                        "
                        @feedback="(id, fb) => $emit('feedback', id, fb)"
                    />

                    <!-- Typing indicator -->
                    <div v-if="showTypingIndicator" class="flex items-center gap-2 py-1">
                        <div class="flex gap-1">
                            <span class="w-2 h-2 rounded-full bg-neutral-400 dark:bg-neutral-500 animate-bounce" style="animation-delay: 0ms" />
                            <span class="w-2 h-2 rounded-full bg-neutral-400 dark:bg-neutral-500 animate-bounce" style="animation-delay: 160ms" />
                            <span class="w-2 h-2 rounded-full bg-neutral-400 dark:bg-neutral-500 animate-bounce" style="animation-delay: 320ms" />
                        </div>
                        <span class="text-xs text-neutral-400 dark:text-neutral-500">Thinking…</span>
                    </div>
                </div>

            </div>
        </div>
    </div>
</template>

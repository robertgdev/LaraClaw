<script setup lang="ts">
import { ref, computed, watch, onMounted, nextTick, type PropType } from 'vue';
import { cn } from '@/lib/utils';
import type { GatewayMessage } from '@/types/chat';
import { getToolCallsFromMessage } from '@/lib/chat-utils';
import { useChatSettingsStore } from '@/stores/chatSettings';
import MessageItem from './MessageItem.vue';

const props = defineProps({
    messages: {
        type: Array as PropType<GatewayMessage[]>,
        required: true,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    empty: {
        type: Boolean,
        default: false,
    },
    waitingForResponse: {
        type: Boolean,
        default: false,
    },
    sessionKey: {
        type: String,
        default: '',
    },
});

const settingsStore = useChatSettingsStore();

const scrollRef = ref<HTMLDivElement | null>(null);
const anchorRef = ref<HTMLDivElement | null>(null);
const isUserScrolling = ref(false);

// Compute linked tool call IDs
const linkedToolCallIds = computed(() => {
    const ids = new Set<string>();
    for (const message of props.messages) {
        if (message.role !== 'assistant') continue;
        const toolCalls = getToolCallsFromMessage(message);
        for (const toolCall of toolCalls) {
            const toolCallId = typeof toolCall.id === 'string' ? toolCall.id.trim() : '';
            if (toolCallId) ids.add(toolCallId);
        }
    }
    return ids;
});

// Filter messages for display
const displayMessages = computed(() => {
    return props.messages.filter((msg) => {
        if (msg.role !== 'toolResult') return true;
        if (!settingsStore.settings.showToolMessages) return true;
        const toolCallId = typeof msg.toolCallId === 'string' ? msg.toolCallId.trim() : '';
        if (!toolCallId) return true;
        return !linkedToolCallIds.value.has(toolCallId);
    });
});

// Build tool results map
const toolResultsByCallId = computed(() => {
    const map = new Map<string, GatewayMessage>();
    for (const message of props.messages) {
        if (message.role !== 'toolResult') continue;
        const toolCallId = message.toolCallId;
        if (typeof toolCallId === 'string' && toolCallId.trim().length > 0) {
            map.set(toolCallId, message);
        }
    }
    return map;
});

// Find last assistant and user indices
const lastAssistantIndex = computed(() => {
    const indices = displayMessages.value
        .map((message, index) => ({ message, index }))
        .filter(({ message }) => message.role !== 'user')
        .map(({ index }) => index);
    return indices.pop();
});

const lastUserIndex = computed(() => {
    const indices = displayMessages.value
        .map((message, index) => ({ message, index }))
        .filter(({ message }) => message.role === 'user')
        .map(({ index }) => index);
    return indices.pop();
});

// Show typing indicator
const showTypingIndicator = computed(() => {
    return (
        props.waitingForResponse &&
        (typeof lastUserIndex.value !== 'number' ||
            typeof lastAssistantIndex.value !== 'number' ||
            lastAssistantIndex.value < lastUserIndex.value)
    );
});

// Check if scrolled to bottom
function isScrolledToBottom(): boolean {
    if (!scrollRef.value) return true;
    const { scrollTop, scrollHeight, clientHeight } = scrollRef.value;
    return scrollHeight - scrollTop - clientHeight < 50;
}

// Auto-scroll to bottom
function scrollToBottom(smooth: boolean = false) {
    nextTick(() => {
        if (scrollRef.value) {
            scrollRef.value.scrollTo({
                top: scrollRef.value.scrollHeight,
                behavior: smooth ? 'smooth' : 'auto',
            });
        }
    });
}

// Handle user scroll
function handleScroll() {
    if (scrollRef.value) {
        // If user scrolls up, mark as scrolling
        const { scrollTop, scrollHeight, clientHeight } = scrollRef.value;
        isUserScrolling.value = scrollHeight - scrollTop - clientHeight > 100;
    }
}

// Watch for new messages
watch(
    () => props.messages.length,
    () => {
        // Only auto-scroll if user is near bottom
        if (!isUserScrolling.value) {
            scrollToBottom();
        }
    },
);

// Scroll to bottom when waiting for response changes
watch(
    () => props.waitingForResponse,
    (newVal) => {
        if (newVal && !isUserScrolling.value) {
            scrollToBottom();
        }
    },
);

onMounted(() => {
    scrollToBottom();
});
</script>

<template>
    <div 
        class="flex-1 min-h-0 overflow-y-auto overflow-x-hidden bg-surface dark:bg-primary-950" 
        ref="scrollRef"
        @scroll="handleScroll"
    >
        <div class="flex flex-col min-h-full">
            <!-- Main content area - pushes content to bottom -->
            <div class="flex-1 flex flex-col justify-end">
                <div class="pt-6 pb-4">
                    <div class="mx-auto w-full max-w-full px-5 sm:max-w-[768px] sm:min-w-[400px]">
                        <!-- Empty state -->
                        <div v-if="empty && !loading" class="flex items-center justify-center min-h-[200px]">
                            <div class="text-center text-primary-500 dark:text-primary-400">
                                <p class="text-lg">Start a conversation</p>
                                <p class="text-sm mt-2">Type a message below to begin</p>
                            </div>
                        </div>

                        <!-- Messages -->
                        <div v-else class="flex flex-col space-y-6">
                            <MessageItem
                                v-for="(chatMessage, index) in displayMessages"
                                :key="chatMessage.__optimisticId || chatMessage.id || index"
                                :message="chatMessage"
                                :tool-results-by-call-id="
                                    chatMessage.role === 'assistant' && getToolCallsFromMessage(chatMessage).length > 0
                                        ? toolResultsByCallId
                                        : undefined
                                "
                                :force-actions-visible="typeof lastAssistantIndex === 'number' && index === lastAssistantIndex"
                            />

                            <!-- Typing indicator -->
                            <div v-if="showTypingIndicator" class="py-2">
                                <div class="flex items-center gap-2 text-primary-500 dark:text-primary-400">
                                    <div class="flex gap-1">
                                        <span class="w-2 h-2 bg-primary-400 dark:bg-primary-500 rounded-full animate-bounce" style="animation-delay: 0ms"></span>
                                        <span class="w-2 h-2 bg-primary-400 dark:bg-primary-500 rounded-full animate-bounce" style="animation-delay: 150ms"></span>
                                        <span class="w-2 h-2 bg-primary-400 dark:bg-primary-500 rounded-full animate-bounce" style="animation-delay: 300ms"></span>
                                    </div>
                                    <span class="text-sm">Thinking...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

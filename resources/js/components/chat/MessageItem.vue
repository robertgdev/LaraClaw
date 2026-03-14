<script setup lang="ts">
import { computed, type PropType } from 'vue';
import type { GatewayMessage, ToolPart, FeedbackValue } from '@/types/chat';
import { textFromMessage, getToolCallsFromMessage, getMessageTimestamp } from '@/lib/chat-utils';
import { useChatSettingsStore } from '@/stores/chatSettings';
import { renderMarkdown } from '@/lib/markdown';
import Tool from './Tool.vue';
import Thinking from './Thinking.vue';
import FeedbackButtons from './FeedbackButtons.vue';

const props = defineProps({
    message: {
        type: Object as PropType<GatewayMessage>,
        required: true,
    },
    toolResultsByCallId: {
        type: Map as PropType<Map<string, GatewayMessage> | undefined>,
        default: undefined,
    },
});

const emit = defineEmits<{
    feedback: [messageId: string, feedback: FeedbackValue];
}>();

const settingsStore = useChatSettingsStore();

const role = computed(() => props.message.role || 'assistant');
const isUser = computed(() => role.value === 'user');
const isAssistant = computed(() => role.value === 'assistant');
const isToolResult = computed(() => role.value === 'toolResult');

const text = computed(() => textFromMessage(props.message));
const timestamp = computed(() => getMessageTimestamp(props.message));

const assistantParts = computed(() =>
    Array.isArray(props.message.content) ? props.message.content : [],
);

const standaloneToolPart = computed((): ToolPart | null => {
    if (!isToolResult.value) return null;
    return mapStandaloneToolResultToToolPart(props.message);
});

function toolResultText(resultMessage: GatewayMessage | undefined): string {
    if (!resultMessage) return '';
    const content = Array.isArray(resultMessage.content) ? resultMessage.content : [];
    return content
        .map((part) => (part.type === 'text' ? String((part as { text?: string }).text ?? '') : ''))
        .join('')
        .trim();
}

function mapToolCallToToolPart(
    toolCall: { id?: string; name?: string; arguments?: Record<string, unknown>; partialJson?: string },
    resultMessage: GatewayMessage | undefined,
): ToolPart {
    const hasResult = resultMessage !== undefined;
    const isError = resultMessage?.isError ?? false;
    let state: ToolPart['state'];
    if (!hasResult) {
        state = 'input-available';
    } else if (isError) {
        state = 'output-error';
    } else {
        state = 'output-available';
    }
    const resultTextVal = toolResultText(resultMessage);
    const output =
        resultMessage?.details && typeof resultMessage.details === 'object'
            ? resultMessage.details
            : resultTextVal
              ? { text: resultTextVal }
              : undefined;
    return {
        type: toolCall.name || 'unknown',
        state,
        input: toolCall.arguments,
        output,
        toolCallId: toolCall.id,
        errorText: isError ? resultTextVal || 'Unknown error' : undefined,
    };
}

function mapStandaloneToolResultToToolPart(message: GatewayMessage): ToolPart {
    const isError = Boolean(message.isError);
    const t = toolResultText(message);
    const output =
        message.details && typeof message.details === 'object'
            ? message.details
            : t ? { text: t } : undefined;
    return {
        type:
            typeof message.toolName === 'string' && message.toolName.trim().length > 0
                ? message.toolName
                : 'tool',
        state: isError ? 'output-error' : 'output-available',
        output,
        toolCallId: typeof message.toolCallId === 'string' ? message.toolCallId : undefined,
        errorText: isError ? t || 'Unknown error' : undefined,
    };
}

function formatTime(ts: number): string {
    const date = new Date(ts);
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function copyToClipboard() {
    navigator.clipboard.writeText(text.value);
}

function renderMarkdownContent(content: string): string {
    return renderMarkdown(content);
}

function handleFeedback(feedback: FeedbackValue) {
    const messageId = props.message.messageId || props.message.id;
    if (messageId) {
        emit('feedback', messageId, feedback);
    }
}
</script>

<template>
    <div class="flex flex-col" :class="isUser ? 'items-end' : 'items-start'">

        <!-- ── User message ── -->
        <template v-if="isUser">
            <div class="max-w-[80%] bg-neutral-100 dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-4 py-2.5 rounded-2xl break-words whitespace-normal min-w-0 text-sm leading-relaxed">
                {{ text }}
            </div>
            <div class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                {{ formatTime(timestamp) }}
            </div>
        </template>

        <!-- ── Tool result (standalone) ── -->
        <template v-if="isToolResult && settingsStore.settings.showToolMessages && standaloneToolPart">
            <div class="w-full max-w-2xl">
                <Tool :tool-part="standaloneToolPart" :default-open="false" />
            </div>
        </template>

        <!-- ── Assistant message ── -->
        <template v-if="isAssistant">
            <div class="w-full max-w-2xl">
                <template v-for="(part, index) in assistantParts" :key="index">
                    <!-- Thinking block -->
                    <template v-if="part.type === 'thinking'">
                        <div
                            v-if="'thinking' in part && String(part.thinking ?? '').trim() && settingsStore.settings.showReasoningBlocks"
                            class="mb-2"
                        >
                            <Thinking :content="String(part.thinking ?? '')" />
                        </div>
                    </template>

                    <!-- Text -->
                    <template v-else-if="part.type === 'text'">
                        <div
                            v-if="'text' in part && String(part.text ?? '').trim()"
                            class="text-neutral-900 dark:text-neutral-100 text-sm leading-relaxed break-words markdown-content"
                            v-html="renderMarkdownContent(String(part.text ?? ''))"
                        />
                    </template>

                    <!-- Tool call -->
                    <template v-else-if="part.type === 'toolCall' && settingsStore.settings.showToolMessages">
                        <div class="mt-1 mb-1">
                            <Tool
                                :tool-part="mapToolCallToToolPart(part as any, toolResultsByCallId?.get((part as { id?: string }).id || ''))"
                                :default-open="false"
                            />
                        </div>
                    </template>
                </template>
            </div>

            <!-- Timestamp + feedback row – always visible under assistant messages that have text -->
            <div v-if="text" class="flex items-center gap-2 mt-1.5">
                <span class="text-xs text-neutral-400 dark:text-neutral-500">{{ formatTime(timestamp) }}</span>
                <button
                    type="button"
                    class="text-xs text-neutral-400 dark:text-neutral-500 hover:text-neutral-600 dark:hover:text-neutral-300 transition-colors"
                    title="Copy message"
                    @click="copyToClipboard"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
                    </svg>
                </button>
                <FeedbackButtons
                    :feedback="message.feedback"
                    size="sm"
                    @feedback="handleFeedback"
                />
            </div>
        </template>
    </div>
</template>

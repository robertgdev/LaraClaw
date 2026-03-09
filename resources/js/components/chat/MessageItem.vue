<script setup lang="ts">
import { computed, type PropType } from 'vue';
import { cn } from '@/lib/utils';
import type { GatewayMessage, ToolPart } from '@/types/chat';
import { textFromMessage, getToolCallsFromMessage, getMessageTimestamp } from '@/lib/chat-utils';
import { useChatSettingsStore } from '@/stores/chatSettings';
import { renderMarkdown } from '@/lib/markdown';
import Tool from './Tool.vue';
import Thinking from './Thinking.vue';

const props = defineProps({
    message: {
        type: Object as PropType<GatewayMessage>,
        required: true,
    },
    toolResultsByCallId: {
        type: Map as PropType<Map<string, GatewayMessage> | undefined>,
        default: undefined,
    },
    forceActionsVisible: {
        type: Boolean,
        default: false,
    },
});

const settingsStore = useChatSettingsStore();

const role = computed(() => props.message.role || 'assistant');
const text = computed(() => textFromMessage(props.message));
const isUser = computed(() => role.value === 'user');
const isToolResult = computed(() => role.value === 'toolResult');
const isAssistant = computed(() => role.value === 'assistant');
const timestamp = computed(() => getMessageTimestamp(props.message));

const standaloneToolPart = computed(() => {
    if (!isToolResult.value) return null;
    return mapStandaloneToolResultToToolPart(props.message);
});

const assistantParts = computed(() => {
    return Array.isArray(props.message.content) ? props.message.content : [];
});

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

    const resultText = toolResultText(resultMessage);
    let errorText: string | undefined;
    if (isError) {
        errorText = resultText || 'Unknown error';
    }

    const output =
        resultMessage?.details && typeof resultMessage.details === 'object'
            ? resultMessage.details
            : resultText
              ? { text: resultText }
              : undefined;

    return {
        type: toolCall.name || 'unknown',
        state,
        input: toolCall.arguments,
        output,
        toolCallId: toolCall.id,
        errorText,
    };
}

function toolResultText(resultMessage: GatewayMessage | undefined): string {
    if (!resultMessage) return '';
    const content = Array.isArray(resultMessage.content) ? resultMessage.content : [];
    return content
        .map((part) => (part.type === 'text' ? String((part as { text?: string }).text ?? '') : ''))
        .join('')
        .trim();
}

function mapStandaloneToolResultToToolPart(message: GatewayMessage): ToolPart {
    const isError = Boolean(message.isError);
    const text = toolResultText(message);
    const output =
        message.details && typeof message.details === 'object'
            ? message.details
            : text
              ? { text }
              : undefined;

    return {
        type:
            typeof message.toolName === 'string' && message.toolName.trim().length > 0
                ? message.toolName
                : 'tool',
        state: isError ? 'output-error' : 'output-available',
        output,
        toolCallId: typeof message.toolCallId === 'string' ? message.toolCallId : undefined,
        errorText: isError ? text || 'Unknown error' : undefined,
    };
}

function formatTime(ts: number): string {
    const date = new Date(ts);
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function copyToClipboard() {
    navigator.clipboard.writeText(text.value);
}

// Render markdown for assistant messages
function renderMarkdownContent(content: string): string {
    return renderMarkdown(content);
}
</script>

<template>
    <div
        :class="
            cn(
                'group flex flex-col gap-1',
                isUser ? 'items-end' : 'items-start',
            )
        "
    >
        <!-- User message -->
        <template v-if="isUser">
            <div :class="cn('flex flex-wrap gap-2 mb-2', 'justify-end')">
                <div
                    :class="
                        cn(
                            'text-primary-900 dark:text-primary-100',
                            'bg-primary-100 dark:bg-primary-800 px-4 py-2.5 max-w-[80%] rounded-[12px] break-words whitespace-normal min-w-0',
                        )
                    "
                >
                    {{ text }}
                </div>
            </div>
            <div v-if="forceActionsVisible" class="text-xs text-primary-500 dark:text-primary-400">
                {{ formatTime(timestamp) }}
                <button
                    type="button"
                    class="ml-2 hover:text-primary-700 dark:hover:text-primary-200"
                    @click="copyToClipboard"
                >
                    Copy
                </button>
            </div>
        </template>

        <!-- Tool result -->
        <template v-if="isToolResult && settingsStore.settings.showToolMessages && standaloneToolPart">
            <div class="w-full max-w-[900px] mt-2 flex flex-col gap-3">
                <Tool :tool-part="standaloneToolPart" :default-open="false" />
            </div>
        </template>

        <!-- Assistant message -->
        <template v-if="isAssistant">
            <template v-for="(part, index) in assistantParts" :key="index">
                <!-- Thinking -->
                <template v-if="part.type === 'thinking'">
                    <div
                        v-if="'thinking' in part && String(part.thinking ?? '').trim() && settingsStore.settings.showReasoningBlocks"
                        class="w-full max-w-[900px]"
                    >
                        <Thinking :content="String(part.thinking ?? '')" />
                    </div>
                </template>

                <!-- Text -->
                <template v-else-if="part.type === 'text'">
                    <div
                        v-if="'text' in part && String(part.text ?? '').trim()"
                        class="flex gap-3 w-full"
                    >
                        <div
                            class="text-primary-900 dark:text-primary-100 bg-transparent w-full rounded-[12px] break-words min-w-0 markdown-content"
                            v-html="renderMarkdownContent(String(part.text ?? ''))"
                        />
                    </div>
                </template>

                <!-- Tool call -->
                <template v-else-if="settingsStore.settings.showToolMessages">
                    <div
                        v-if="part.type === 'toolCall'"
                        :key="`tool-${(part as { id?: string }).id || index}`"
                        class="w-full max-w-[900px] mt-1"
                    >
                        <Tool
                            :tool-part="mapToolCallToToolPart(part as any, toolResultsByCallId?.get((part as { id?: string }).id || ''))"
                            :default-open="false"
                        />
                    </div>
                </template>
            </template>

            <!-- Actions bar -->
            <div
                v-if="forceActionsVisible || text"
                :class="cn('text-primary-600 dark:text-primary-400 flex items-center gap-2', isUser ? 'justify-end' : 'justify-start')"
            >
                <span class="text-xs">{{ formatTime(timestamp) }}</span>
                <button
                    type="button"
                    class="text-xs hover:text-primary-700 dark:hover:text-primary-200 opacity-0 group-hover:opacity-100 transition-opacity"
                    @click="copyToClipboard"
                >
                    Copy
                </button>
            </div>
        </template>
    </div>
</template>

<script setup lang="ts">
import { computed, type PropType } from 'vue';
import { cn } from '@/lib/utils';
import Button from '@/components/ui/Button.vue';
import Collapsible from '@/components/ui/Collapsible.vue';
import type { ToolPart } from '@/types/chat';

const props = defineProps({
    toolPart: {
        type: Object as PropType<ToolPart>,
        required: true,
    },
    defaultOpen: {
        type: Boolean,
        default: false,
    },
});

const { state, input, output, toolCallId } = props.toolPart;

function formatValue(value: unknown): unknown {
    if (value === null) return 'null';
    if (value === undefined) return 'undefined';
    if (typeof value === 'string') {
        try {
            const parsed = JSON.parse(value);
            return parsed;
        } catch {
            return value;
        }
    }
    return value;
}

function renderValue(value: unknown): string {
    const formatted = formatValue(value);
    if (typeof formatted === 'object' && formatted !== null) {
        return JSON.stringify(formatted, null, 2);
    }
    return String(formatted);
}

const inputEntries = computed(() => {
    if (!input || Object.keys(input).length === 0) return [];
    return Object.entries(input);
});

const toolCallIdShort = computed(() => {
    if (!toolCallId) return '';
    return toolCallId.slice(0, 16) + '...';
});
</script>

<template>
    <div class="inline-flex flex-col">
        <Collapsible :default-open="defaultOpen">
            <template #default="{ isOpen, toggle }">
                <button
                    type="button"
                    :class="
                        cn(
                            'h-auto gap-1.5 px-1.5 py-0.5 -mx-2',
                            'relative inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-lg text-sm font-medium transition-colors',
                            'text-primary-900 dark:text-primary-100 hover:bg-primary-200 dark:hover:bg-primary-800 hover:text-primary-950 dark:hover:text-primary-50',
                        )
                    "
                    @click="toggle"
                >
                    <span class="text-sm font-medium text-primary-900 dark:text-primary-100">
                        {{ toolPart.type }}
                    </span>
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width="14"
                        height="14"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        :class="cn('text-primary-900 dark:text-primary-100 transition-transform duration-150', isOpen && 'rotate-180')"
                    >
                        <path d="m6 9 6 6 6-6" />
                    </svg>
                </button>

                <div v-if="isOpen" class="mt-1">
                    <div class="space-y-2 bg-primary-100 dark:bg-primary-900 p-2 border border-primary-200 dark:border-primary-700">
                        <!-- Input -->
                        <div v-if="inputEntries.length > 0" class="border border-primary-200 dark:border-primary-600 bg-primary-50 dark:bg-primary-800 p-3">
                            <h4 class="text-primary-600 dark:text-primary-300 mb-2 text-xs font-medium">Input</h4>
                            <div class="max-h-40 overflow-auto space-y-2 font-mono text-xs text-primary-800 dark:text-primary-200">
                                <div v-for="[key, value] in inputEntries" :key="key" class="break-all">
                                    <span class="text-primary-500 dark:text-primary-400">{{ key }}:</span>
                                    <span class="text-primary-700 dark:text-primary-300 ml-1">{{ renderValue(value) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Output -->
                        <div v-if="output" class="border border-primary-200 dark:border-primary-600 bg-primary-50 dark:bg-primary-800 p-3">
                            <h4 class="text-primary-600 dark:text-primary-300 mb-2 text-xs font-medium">Output</h4>
                            <div class="max-h-40 overflow-auto font-mono text-xs text-primary-800 dark:text-primary-200">
                                <pre class="whitespace-pre-wrap break-all">{{ renderValue(output) }}</pre>
                            </div>
                        </div>

                        <!-- Error -->
                        <div v-if="state === 'output-error' && toolPart.errorText" class="rounded-md bg-red-50 dark:bg-red-900/30 p-2">
                            <h4 class="mb-1 text-xs font-medium text-red-600 dark:text-red-400">Error</h4>
                            <div class="text-xs text-red-700 dark:text-red-300">{{ toolPart.errorText }}</div>
                        </div>

                        <!-- Processing -->
                        <div v-if="state === 'input-streaming'" class="text-primary-500 dark:text-primary-400 text-xs">
                            Processing...
                        </div>

                        <!-- Tool call ID -->
                        <div v-if="toolCallId" class="text-primary-400 dark:text-primary-500 text-xs">
                            <span class="font-mono tabular-nums">ID: {{ toolCallIdShort }}</span>
                        </div>
                    </div>
                </div>
            </template>
        </Collapsible>
    </div>
</template>

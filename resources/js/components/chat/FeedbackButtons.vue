<script setup lang="ts">
import { computed  } from 'vue';
import type {PropType} from 'vue';
import { cn } from '@/lib/utils';
import type { FeedbackValue } from '@/types/chat';

const props = defineProps({
    feedback: {
        type: Number as PropType<FeedbackValue | null | undefined>,
        default: null,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    size: {
        type: String as PropType<'sm' | 'md'>,
        default: 'md',
    },
});

const emit = defineEmits<{
    feedback: [value: FeedbackValue];
}>();

const isPositive = computed(() => props.feedback === 1);
const isNeutral = computed(() => props.feedback === 0);
const isNegative = computed(() => props.feedback === -1);

function handlePositive() {
    if (props.disabled) return;
    emit('feedback', 1);
}

function handleNeutral() {
    if (props.disabled) return;
    emit('feedback', 0);
}

function handleNegative() {
    if (props.disabled) return;
    emit('feedback', -1);
}

const buttonSize = computed(() => props.size === 'sm' ? 'w-5 h-5 text-xs' : 'w-6 h-6 text-sm');
const iconSize = computed(() => props.size === 'sm' ? 12 : 14);
</script>

<template>
    <div class="flex items-center gap-0.5">
        <!-- Thumbs Up (Positive) -->
        <button
            type="button"
            :disabled="disabled"
            :class="cn(
                'inline-flex items-center justify-center rounded transition-colors',
                buttonSize,
                isPositive
                    ? 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400'
                    : 'text-primary-400 dark:text-primary-500 hover:bg-primary-100 dark:hover:bg-primary-800 hover:text-green-600 dark:hover:text-green-400',
                disabled && 'opacity-50 cursor-not-allowed'
            )"
            :title="isPositive ? 'Helpful (selected)' : 'Mark as helpful'"
            @click="handlePositive"
        >
            <svg xmlns="http://www.w3.org/2000/svg" :width="iconSize" :height="iconSize" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M7 10v12" />
                <path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 16.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2h0a3.13 3.13 0 0 1 3 3.88Z" />
            </svg>
        </button>

        <!-- Neutral -->
        <button
            type="button"
            :disabled="disabled"
            :class="cn(
                'inline-flex items-center justify-center rounded transition-colors',
                buttonSize,
                isNeutral
                    ? 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-600 dark:text-yellow-400'
                    : 'text-primary-400 dark:text-primary-500 hover:bg-primary-100 dark:hover:bg-primary-800 hover:text-yellow-600 dark:hover:text-yellow-400',
                disabled && 'opacity-50 cursor-not-allowed'
            )"
            :title="isNeutral ? 'Neutral (selected)' : 'Mark as neutral'"
            @click="handleNeutral"
        >
            <svg xmlns="http://www.w3.org/2000/svg" :width="iconSize" :height="iconSize" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10" />
                <line x1="8" y1="15" x2="16" y2="15" />
                <line x1="9" y1="9" x2="9.01" y2="9" />
                <line x1="15" y1="9" x2="15.01" y2="9" />
            </svg>
        </button>

        <!-- Thumbs Down (Negative) -->
        <button
            type="button"
            :disabled="disabled"
            :class="cn(
                'inline-flex items-center justify-center rounded transition-colors',
                buttonSize,
                isNegative
                    ? 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400'
                    : 'text-primary-400 dark:text-primary-500 hover:bg-primary-100 dark:hover:bg-primary-800 hover:text-red-600 dark:hover:text-red-400',
                disabled && 'opacity-50 cursor-not-allowed'
            )"
            :title="isNegative ? 'Not helpful (selected)' : 'Mark as not helpful'"
            @click="handleNegative"
        >
            <svg xmlns="http://www.w3.org/2000/svg" :width="iconSize" :height="iconSize" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 14V2" />
                <path d="M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 7.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.76a2 2 0 0 0-1.79 1.11L12 22h0a3.13 3.13 0 0 1-3-3.88Z" />
            </svg>
        </button>
    </div>
</template>

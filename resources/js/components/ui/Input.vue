<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

interface Props {
    modelValue?: string;
    type?: string;
    placeholder?: string;
    disabled?: boolean;
    size?: 'sm' | 'default' | 'lg';
    unstyled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: '',
    type: 'text',
    placeholder: '',
    disabled: false,
    size: 'default',
    unstyled: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const wrapperClasses = computed(() => {
    if (props.unstyled) return undefined;
    return cn(
        'relative inline-flex w-full rounded-lg border border-primary-200 bg-surface not-dark:bg-clip-padding text-base text-primary-900 shadow-xs/5 ring-primary-500/24 transition-shadow before:pointer-events-none before:absolute before:inset-0 before:rounded-[calc(var(--radius-lg)-1px)] not-has-disabled:not-has-focus-visible:not-has-aria-invalid:before:shadow-[0_1px_--theme(--color-ink/6%)] has-focus-visible:has-aria-invalid:border-destructive/64 has-focus-visible:has-aria-invalid:ring-destructive/16 has-aria-invalid:border-destructive/36 has-focus-visible:border-primary-500 has-autofill:bg-primary-100 has-disabled:opacity-64 has-[:disabled,:focus-visible,[aria-invalid]]:shadow-none has-focus-visible:ring-[3px] sm:text-sm dark:bg-primary-900/32 dark:has-autofill:bg-primary-900/30 dark:has-aria-invalid:ring-destructive/24 dark:not-has-disabled:not-has-focus-visible:not-has-aria-invalid:before:shadow-[0_-1px_--theme(--color-surface/6%)]',
    );
});

const inputClasses = computed(() =>
    cn(
        'h-8.5 w-full min-w-0 rounded-[inherit] px-[calc(--spacing(3)-1px)] leading-8.5 outline-none placeholder:text-primary-600/70 sm:h-7.5 sm:leading-7.5 [transition:background-color_5000000s_ease-in-out_0s]',
        props.size === 'sm' && 'h-7.5 px-[calc(--spacing(2.5)-1px)] leading-7.5 sm:h-6.5 sm:leading-6.5',
        props.size === 'lg' && 'h-9.5 leading-9.5 sm:h-8.5 sm:leading-8.5',
        props.type === 'search' && '[&::-webkit-search-cancel-button]:appearance-none [&::-webkit-search-decoration]:appearance-none [&::-webkit-search-results-button]:appearance-none [&::-webkit-search-results-decoration]:appearance-none',
        props.type === 'file' && 'text-primary-600 file:me-3 file:bg-transparent file:font-medium file:text-primary-900 file:text-sm',
    ),
);

function handleInput(event: Event) {
    const target = event.target as HTMLInputElement;
    emit('update:modelValue', target.value);
}
</script>

<template>
    <span
        :class="wrapperClasses"
        :data-size="size"
        data-slot="input-control"
    >
        <input
            :type="type"
            :value="modelValue"
            :placeholder="placeholder"
            :disabled="disabled"
            :class="inputClasses"
            data-slot="input"
            @input="handleInput"
        />
    </span>
</template>

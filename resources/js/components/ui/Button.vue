<script setup lang="ts">
import { computed } from 'vue';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const buttonVariants = cva(
    'relative inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-lg text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary-950 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 select-none duration-150',
    {
        defaultVariants: {
            size: 'default',
            variant: 'default',
        },
        variants: {
            size: {
                default: 'h-9 px-4',
                sm: 'h-8 px-3',
                lg: 'h-10 px-5',
                icon: 'size-9',
                'icon-sm': 'size-8',
                'icon-md': 'size-10',
                'icon-xl': 'size-11 [&_svg]:size-5',
            },
            variant: {
                default:
                    'bg-primary-950 text-primary-50 hover:bg-primary-900 dark:bg-primary-50 dark:text-primary-950 dark:hover:bg-primary-200 shadow-sm outline outline-primary-900/10 shadow-2xs',
                secondary:
                    'bg-primary-50 text-primary-950 hover:bg-primary-200 dark:bg-primary-900 dark:text-primary-50 dark:hover:bg-primary-800 outline outline-primary-900/10 shadow-2xs',
                outline:
                    'border-primary-200 bg-transparent text-primary-900 hover:bg-primary-50 dark:border-primary-700 dark:text-primary-100 dark:hover:bg-primary-800 shadow-2xs outline outline-primary-900/10',
                ghost: 'text-primary-900 hover:bg-primary-200 hover:text-primary-950 dark:text-primary-100 dark:hover:bg-primary-800 dark:hover:text-primary-50',
                destructive: 'bg-red-600 text-primary-50 hover:bg-red-700 dark:bg-red-700 dark:hover:bg-red-600 shadow-sm',
            },
        },
    },
);

type ButtonVariants = VariantProps<typeof buttonVariants>;

interface Props {
    variant?: ButtonVariants['variant'];
    size?: ButtonVariants['size'];
    disabled?: boolean;
    type?: 'button' | 'submit' | 'reset';
    as?: string;
}

const props = withDefaults(defineProps<Props>(), {
    variant: 'default',
    size: 'default',
    disabled: false,
    type: 'button',
    as: 'button',
});

const classes = computed(() =>
    cn(buttonVariants({ variant: props.variant, size: props.size })),
);
</script>

<template>
    <component
        :is="as"
        :type="as === 'button' ? type : undefined"
        :class="classes"
        :disabled="disabled"
        data-slot="button"
    >
        <slot />
    </component>
</template>

<script setup lang="ts">
import { inject, Teleport, Transition, computed } from 'vue';
import { cn } from '@/lib/utils';

interface TooltipContext {
    isOpen: { value: boolean };
    open: () => void;
    close: () => void;
}

const TooltipContextKey: import('vue').InjectionKey<TooltipContext> = Symbol('tooltip-context');

interface Props {
    side?: 'top' | 'bottom' | 'left' | 'right';
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    side: 'top',
});

const context = inject<TooltipContext>(TooltipContextKey);
if (!context) throw new Error('TooltipContent must be used within TooltipRoot');

const positionClasses: Record<string, string> = {
    top: 'bottom-full left-1/2 -translate-x-1/2 mb-2',
    bottom: 'top-full left-1/2 -translate-x-1/2 mt-2',
    left: 'right-full top-1/2 -translate-y-1/2 mr-2',
    right: 'left-full top-1/2 -translate-y-1/2 ml-2',
};

const classes = computed(() =>
    cn(
        'fixed z-50 rounded-md bg-primary-900 px-3 py-1.5 text-xs text-primary-50 shadow-md',
        positionClasses[props.side],
        props.class,
    ),
);
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-150"
            leave-active-class="transition-opacity duration-150"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div v-if="context.isOpen.value" :class="classes">
                <slot />
            </div>
        </Transition>
    </Teleport>
</template>

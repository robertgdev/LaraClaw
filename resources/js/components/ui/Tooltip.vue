<script setup lang="ts">
import { ref, provide, inject, type InjectionKey, Teleport, Transition } from 'vue';
import { cn } from '@/lib/utils';

interface TooltipContext {
    isOpen: Ref<boolean>;
    open: () => void;
    close: () => void;
}

type Ref<T> = import('vue').Ref<T>;

const TooltipContextKey: InjectionKey<TooltipContext> = Symbol('tooltip-context');

interface TooltipRootProps {
    delayDuration?: number;
}

const props = withDefaults(defineProps<TooltipRootProps>(), {
    delayDuration: 200,
});

const isOpen = ref(false);
let openTimeout: ReturnType<typeof setTimeout> | null = null;

function open() {
    if (openTimeout) clearTimeout(openTimeout);
    openTimeout = setTimeout(() => {
        isOpen.value = true;
    }, props.delayDuration);
}

function close() {
    if (openTimeout) clearTimeout(openTimeout);
    isOpen.value = false;
}

provide(TooltipContextKey, { isOpen, open, close });
</script>

<template>
    <div class="relative inline-flex">
        <slot />
    </div>
</template>

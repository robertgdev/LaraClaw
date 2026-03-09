<script setup lang="ts">
import { inject, cloneVNode, type VNode } from 'vue';

interface TooltipContext {
    isOpen: { value: boolean };
    open: () => void;
    close: () => void;
}

const TooltipContextKey: import('vue').InjectionKey<TooltipContext> = Symbol('tooltip-context');

const context = inject<TooltipContext>(TooltipContextKey);
if (!context) throw new Error('TooltipTrigger must be used within TooltipRoot');
</script>

<template>
    <slot
        :onMouseenter="context.open"
        :onMouseleave="context.close"
        :onFocus="context.open"
        :onBlur="context.close"
    />
</template>

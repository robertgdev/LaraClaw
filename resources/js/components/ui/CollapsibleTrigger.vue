<script setup lang="ts">
import { inject, type InjectionKey, type Ref } from 'vue';

interface CollapsibleContext {
    isOpen: Ref<boolean>;
    toggle: () => void;
}

const CollapsibleContextKey: InjectionKey<CollapsibleContext> = Symbol('collapsible-context');

const context = inject<CollapsibleContext>(CollapsibleContextKey);
if (!context) throw new Error('CollapsibleTrigger must be used within Collapsible');
</script>

<template>
    <button type="button" @click="context.toggle">
        <slot :isOpen="context.isOpen.value" />
    </button>
</template>

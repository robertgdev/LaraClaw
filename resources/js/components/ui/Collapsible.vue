<script setup lang="ts">
import { ref, provide, inject, type InjectionKey, type Ref } from 'vue';

interface CollapsibleContext {
    isOpen: Ref<boolean>;
    toggle: () => void;
}

const CollapsibleContextKey: InjectionKey<CollapsibleContext> = Symbol('collapsible-context');

interface Props {
    defaultOpen?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    defaultOpen: false,
});

const isOpen = ref(props.defaultOpen);

function toggle() {
    isOpen.value = !isOpen.value;
}

provide(CollapsibleContextKey, { isOpen, toggle });

defineExpose({ isOpen, toggle });
</script>

<template>
    <slot :is-open="isOpen" :toggle="toggle" />
</template>

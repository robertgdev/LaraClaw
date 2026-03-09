<script setup lang="ts">
import { inject, type InjectionKey, type Ref, Transition } from 'vue';
import { cn } from '@/lib/utils';

interface CollapsibleContext {
    isOpen: Ref<boolean>;
    toggle: () => void;
}

const CollapsibleContextKey: InjectionKey<CollapsibleContext> = Symbol('collapsible-context');

interface Props {
    class?: string;
}

const props = defineProps<Props>();

const context = inject<CollapsibleContext>(CollapsibleContextKey);
if (!context) throw new Error('CollapsiblePanel must be used within Collapsible');
</script>

<template>
    <Transition
        enter-active-class="transition-all duration-200 ease-out"
        leave-active-class="transition-all duration-200 ease-out"
        enter-from-class="opacity-0 max-h-0"
        leave-to-class="opacity-0 max-h-0"
    >
        <div v-show="context.isOpen.value" :class="cn('overflow-hidden', props.class)">
            <slot />
        </div>
    </Transition>
</template>

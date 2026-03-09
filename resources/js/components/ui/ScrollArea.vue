<script setup lang="ts">
import { ref, provide, inject, type InjectionKey } from 'vue';
import { cn } from '@/lib/utils';

interface ScrollAreaContext {
    scrollRef: Ref<HTMLDivElement | null>;
    viewportRef: Ref<HTMLDivElement | null>;
}

type Ref<T> = import('vue').Ref<T>;

const ScrollAreaContextKey: InjectionKey<ScrollAreaContext> = Symbol('scroll-area-context');

// ScrollAreaRoot
interface ScrollAreaRootProps {
    class?: string;
}

const rootProps = defineProps<ScrollAreaRootProps>();

const scrollRef = ref<HTMLDivElement | null>(null);
const viewportRef = ref<HTMLDivElement | null>(null);

provide(ScrollAreaContextKey, { scrollRef, viewportRef });
</script>

<template>
    <div :class="cn('relative flex flex-1 min-h-0 flex-col', rootProps.class)">
        <slot />
    </div>
</template>

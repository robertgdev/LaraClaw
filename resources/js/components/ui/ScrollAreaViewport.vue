<script setup lang="ts">
import { inject, type InjectionKey, type Ref } from 'vue';
import { cn } from '@/lib/utils';

interface ScrollAreaContext {
    scrollRef: Ref<HTMLDivElement | null>;
    viewportRef: Ref<HTMLDivElement | null>;
}

const ScrollAreaContextKey: InjectionKey<ScrollAreaContext> = Symbol('scroll-area-context');

interface Props {
    class?: string;
}

const props = defineProps<Props>();

const context = inject<ScrollAreaContext>(ScrollAreaContextKey);
</script>

<template>
    <div
        :ref="(el) => context && (context.viewportRef.value = el as HTMLDivElement)"
        :class="cn('h-full w-full overflow-auto [&>div]:h-full', props.class)"
    >
        <slot />
    </div>
</template>

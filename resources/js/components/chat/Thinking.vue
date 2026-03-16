<script setup lang="ts">
import Collapsible from '@/components/ui/Collapsible.vue';
import { cn } from '@/lib/utils';

defineProps({
    content: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <div class="inline-flex flex-col">
        <Collapsible>
            <template #default="{ isOpen, toggle }">
                <button
                    type="button"
                    :class="
                        cn(
                            'h-auto gap-1.5 px-1.5 py-0.5 -mx-2',
                            'relative inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-lg text-sm font-medium transition-colors',
                            'text-primary-900 dark:text-primary-100 hover:bg-primary-200 dark:hover:bg-primary-800 hover:text-primary-950 dark:hover:text-primary-50',
                        )
                    "
                    @click="toggle"
                >
                    <span class="text-sm font-medium text-primary-900 dark:text-primary-100">Thinking</span>
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width="14"
                        height="14"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        :class="cn('text-primary-900 dark:text-primary-100 transition-transform duration-150', isOpen && 'rotate-180')"
                    >
                        <path d="m6 9 6 6 6-6" />
                    </svg>
                </button>

                <div v-if="isOpen" class="pt-1 mb-3">
                    <p class="text-sm text-primary-700 dark:text-primary-300 whitespace-pre-wrap">
                        {{ content }}
                    </p>
                </div>
            </template>
        </Collapsible>
    </div>
</template>

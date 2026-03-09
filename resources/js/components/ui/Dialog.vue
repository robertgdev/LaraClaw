<script setup lang="ts">
import { ref, watch, provide, computed, Teleport, Transition } from 'vue';
import { cn } from '@/lib/utils';
import Button from './Button.vue';

interface DialogProps {
    open?: boolean;
}

const props = withDefaults(defineProps<DialogProps>(), {
    open: false,
});

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const isOpen = ref(props.open);

watch(
    () => props.open,
    (val) => {
        isOpen.value = val;
    },
);

watch(isOpen, (val) => {
    emit('update:open', val);
    // Handle body scroll
    if (val) {
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = '';
    }
});

function close() {
    isOpen.value = false;
}

function open() {
    isOpen.value = true;
}

function toggle() {
    isOpen.value = !isOpen.value;
}

function handleBackdropClick() {
    close();
}

provide('dialog-context', { isOpen, close, open, toggle });

defineExpose({ close, open, toggle });
</script>

<template>
    <slot :open="isOpen" :close="close" :toggle="toggle" />

    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-150"
            leave-active-class="transition-opacity duration-150"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div
                v-if="isOpen"
                class="fixed inset-0 z-50 bg-ink/40 dark:bg-surface/40"
                @click="handleBackdropClick"
            />
        </Transition>

        <Transition
            enter-active-class="transition-all duration-150"
            leave-active-class="transition-all duration-150"
            enter-from-class="opacity-0 scale-95"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-if="isOpen"
                class="fixed left-1/2 top-1/2 z-50 -translate-x-1/2 -translate-y-1/2"
                role="dialog"
                aria-modal="true"
            >
                <div
                    :class="
                        cn(
                            'w-[min(400px,92vw)] rounded-[20px] border border-primary-200 bg-primary-50 p-0 shadow-lg',
                        )
                    "
                    @click.stop
                >
                    <slot name="content" :close="close" />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

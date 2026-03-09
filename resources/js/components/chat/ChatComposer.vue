<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue';
import { cn } from '@/lib/utils';
import Button from '@/components/ui/Button.vue';
import type { AttachmentFile } from '@/types/chat';

interface Props {
    isLoading?: boolean;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    isLoading: false,
    disabled: false,
});

const emit = defineEmits<{
    submit: [value: string, attachments: AttachmentFile[]];
}>();

const value = ref('');
const textareaRef = ref<HTMLTextAreaElement | null>(null);
const attachments = ref<AttachmentFile[]>([]);
const maxHeight = 240;

// Auto-resize textarea
function adjustHeight() {
    const el = textareaRef.value;
    if (!el) return;

    el.style.height = 'auto';
    const minHeight = 28;
    const measured = Math.max(minHeight, el.scrollHeight);
    el.style.height = `${Math.min(measured, maxHeight)}px`;
}

watch(value, () => {
    nextTick(adjustHeight);
});

function handleSubmit() {
    if (props.disabled) return;

    const body = value.value.trim();
    const validAttachments = attachments.value.filter((a) => !a.error && a.base64);

    if (body.length === 0 && validAttachments.length === 0) return;

    emit('submit', body, validAttachments);
    reset();
}

function handleKeyDown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleSubmit();
    }
}

function reset() {
    value.value = '';
    attachments.value.forEach((attachment) => {
        if (attachment.preview) {
            URL.revokeObjectURL(attachment.preview);
        }
    });
    attachments.value = [];
    nextTick(() => {
        textareaRef.value?.focus();
    });
}

function handleFileSelect(event: Event) {
    const input = event.target as HTMLInputElement;
    const files = input.files;
    if (!files) return;

    for (const file of files) {
        const id = crypto.randomUUID();
        const attachment: AttachmentFile = {
            id,
            file,
            preview: undefined,
            base64: undefined,
        };

        // Create preview for images
        if (file.type.startsWith('image/')) {
            attachment.preview = URL.createObjectURL(file);
        }

        // Read as base64
        const reader = new FileReader();
        reader.onload = () => {
            attachment.base64 = reader.result as string;
        };
        reader.onerror = () => {
            attachment.error = 'Failed to read file';
        };
        reader.readAsDataURL(file);

        attachments.value.push(attachment);
    }

    input.value = '';
}

function removeAttachment(id: string) {
    const index = attachments.value.findIndex((a) => a.id === id);
    if (index >= 0) {
        const attachment = attachments.value[index];
        if (attachment.preview) {
            URL.revokeObjectURL(attachment.preview);
        }
        attachments.value.splice(index, 1);
    }
}

const submitDisabled = computed(() => {
    return props.disabled || props.isLoading;
});

defineExpose({
    reset,
    setValue: (v: string) => {
        value.value = v;
    },
});
</script>

<template>
    <div class="mx-auto w-full max-w-full px-5 sm:max-w-[768px] sm:min-w-[400px] relative pb-3 bg-surface dark:bg-primary-950">
        <div
            :class="
                cn(
                    'bg-surface dark:bg-primary-800 cursor-text rounded-[22px] outline outline-ink/10 dark:outline-primary-600 shadow-[0px_12px_32px_0px_rgba(0,0,0,0.05)] py-3 gap-3 flex flex-col',
                    disabled && 'cursor-not-allowed opacity-60',
                )
            "
            @click="!disabled && textareaRef?.focus()"
        >
            <!-- Attachment previews -->
            <div v-if="attachments.length > 0" class="flex flex-wrap gap-2 px-4">
                <div
                    v-for="attachment in attachments"
                    :key="attachment.id"
                    class="relative group"
                >
                    <img
                        v-if="attachment.preview"
                        :src="attachment.preview"
                        :alt="attachment.file.name"
                        class="w-16 h-16 object-cover rounded-lg"
                    />
                    <div
                        v-else
                        class="w-16 h-16 bg-primary-100 dark:bg-primary-800 rounded-lg flex items-center justify-center"
                    >
                        <span class="text-xs text-primary-600 dark:text-primary-300">{{ attachment.file.name.slice(-8) }}</span>
                    </div>
                    <button
                        type="button"
                        class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white rounded-full text-xs opacity-0 group-hover:opacity-100 transition-opacity"
                        @click.stop="removeAttachment(attachment.id)"
                    >
                        ×
                    </button>
                </div>
            </div>

            <!-- Textarea -->
            <textarea
                ref="textareaRef"
                v-model="value"
                :readonly="isLoading"
                placeholder="Type a message…"
                :class="
                    cn(
                        'text-primary-950 dark:text-primary-100 min-h-[28px] w-full resize-none border-none bg-transparent shadow-none outline-none focus-visible:ring-0 pl-4 pr-1 text-[15px] leading-[22px] placeholder:text-primary-500 dark:placeholder:text-primary-400',
                    )
                "
                rows="1"
                @keydown="handleKeyDown"
            />

            <!-- Actions -->
            <div class="flex items-center gap-2 min-h-8 flex-nowrap px-3 justify-end">
                <!-- Attachment button -->
                <label
                    :class="
                        cn(
                            'inline-flex items-center justify-center rounded-full size-8 text-primary-900 dark:text-primary-100 hover:bg-primary-200 dark:hover:bg-primary-700 cursor-pointer transition-colors',
                            disabled && 'pointer-events-none opacity-50',
                        )
                    "
                >
                    <input
                        type="file"
                        accept="image/*"
                        class="hidden"
                        :disabled="disabled"
                        multiple
                        @change="handleFileSelect"
                    />
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width="20"
                        height="20"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" />
                    </svg>
                </label>

                <!-- Send button -->
                <Button
                    size="icon-sm"
                    variant="default"
                    :disabled="submitDisabled"
                    class="rounded-full"
                    @click="handleSubmit"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width="20"
                        height="20"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M12 19V5M5 12l7-7 7 7" />
                    </svg>
                </Button>
            </div>
        </div>
    </div>
</template>

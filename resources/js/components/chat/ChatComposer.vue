<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue';
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
const MAX_TEXTAREA_HEIGHT = 220;

function adjustHeight() {
    const el = textareaRef.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, MAX_TEXTAREA_HEIGHT)}px`;
}

watch(value, () => nextTick(adjustHeight));

function handleSubmit() {
    if (props.disabled || props.isLoading) return;
    const body = value.value.trim();
    const validAttachments = attachments.value.filter((a) => !a.error && a.base64);
    if (!body && validAttachments.length === 0) return;
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
    attachments.value.forEach((a) => { if (a.preview) URL.revokeObjectURL(a.preview); });
    attachments.value = [];
    nextTick(() => textareaRef.value?.focus());
}

function handleFileSelect(event: Event) {
    const input = event.target as HTMLInputElement;
    if (!input.files) return;
    for (const file of input.files) {
        const id = crypto.randomUUID();
        const attachment: AttachmentFile = { id, file, preview: undefined, base64: undefined };
        if (file.type.startsWith('image/')) {
            attachment.preview = URL.createObjectURL(file);
        }
        const reader = new FileReader();
        reader.onload = () => { attachment.base64 = reader.result as string; };
        reader.onerror = () => { attachment.error = 'Failed to read file'; };
        reader.readAsDataURL(file);
        attachments.value.push(attachment);
    }
    input.value = '';
}

function removeAttachment(id: string) {
    const idx = attachments.value.findIndex((a) => a.id === id);
    if (idx >= 0) {
        const a = attachments.value[idx];
        if (a.preview) URL.revokeObjectURL(a.preview);
        attachments.value.splice(idx, 1);
    }
}

const canSubmit = computed(() => !props.disabled && !props.isLoading);

defineExpose({ reset, setValue: (v: string) => { value.value = v; } });
</script>

<template>
    <div class="flex-shrink-0 px-4 pb-4 pt-2 bg-white dark:bg-neutral-950">
        <div class="mx-auto w-full max-w-2xl">
            <div
                class="flex flex-col bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-700 rounded-2xl shadow-sm overflow-hidden"
                :class="disabled ? 'opacity-60' : ''"
                @click="!disabled && textareaRef?.focus()"
            >
                <!-- Attachment previews -->
                <div v-if="attachments.length > 0" class="flex flex-wrap gap-2 px-3 pt-3">
                    <div
                        v-for="attachment in attachments"
                        :key="attachment.id"
                        class="relative group"
                    >
                        <img
                            v-if="attachment.preview"
                            :src="attachment.preview"
                            :alt="attachment.file.name"
                            class="w-14 h-14 object-cover rounded-lg border border-neutral-200 dark:border-neutral-700"
                        />
                        <div
                            v-else
                            class="w-14 h-14 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-neutral-100 dark:bg-neutral-800 flex items-center justify-center"
                        >
                            <span class="text-xs text-neutral-500 dark:text-neutral-400 text-center px-1 break-all leading-tight">
                                {{ attachment.file.name.slice(-8) }}
                            </span>
                        </div>
                        <button
                            type="button"
                            class="absolute -top-1.5 -right-1.5 w-5 h-5 rounded-full bg-neutral-700 text-white text-[11px] flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity"
                            @click.stop="removeAttachment(attachment.id)"
                        >
                            ×
                        </button>
                    </div>
                </div>

                <!-- Text input -->
                <textarea
                    ref="textareaRef"
                    v-model="value"
                    :readonly="isLoading"
                    placeholder="Type a message…"
                    rows="1"
                    class="w-full resize-none border-none bg-transparent outline-none text-sm text-neutral-900 dark:text-neutral-100 placeholder:text-neutral-400 dark:placeholder:text-neutral-500 px-4 pt-3 pb-0 leading-relaxed"
                    style="min-height: 44px; max-height: 220px;"
                    @keydown="handleKeyDown"
                />

                <!-- Bottom action row -->
                <div class="flex items-center justify-between px-3 py-2">
                    <!-- Attach file -->
                    <label
                        class="flex items-center justify-center w-8 h-8 rounded-lg text-neutral-400 dark:text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800 hover:text-neutral-600 dark:hover:text-neutral-300 cursor-pointer transition-colors"
                        :class="disabled ? 'pointer-events-none opacity-40' : ''"
                        title="Attach image"
                    >
                        <input
                            type="file"
                            accept="image/*"
                            class="hidden"
                            multiple
                            :disabled="disabled"
                            @change="handleFileSelect"
                        />
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" />
                        </svg>
                    </label>

                    <!-- Send button -->
                    <button
                        type="button"
                        :disabled="!canSubmit"
                        class="flex items-center justify-center w-8 h-8 rounded-xl transition-colors"
                        :class="canSubmit
                            ? 'bg-neutral-900 dark:bg-neutral-100 text-white dark:text-neutral-900 hover:bg-neutral-700 dark:hover:bg-neutral-300'
                            : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-300 dark:text-neutral-600 cursor-not-allowed'"
                        title="Send (Enter)"
                        @click="handleSubmit"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="19" x2="12" y2="5" />
                            <polyline points="5 12 12 5 19 12" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

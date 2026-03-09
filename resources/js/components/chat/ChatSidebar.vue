<script setup lang="ts">
import { ref, computed, type PropType } from 'vue';
import { cn } from '@/lib/utils';
import Button from '@/components/ui/Button.vue';
import type { SessionMeta } from '@/types/chat';
import { textFromMessage, formatTimestamp } from '@/lib/chat-utils';

const props = defineProps({
    sessions: {
        type: Array as PropType<SessionMeta[]>,
        required: true,
    },
    activeFriendlyId: {
        type: String,
        default: '',
    },
    isCollapsed: {
        type: Boolean,
        default: false,
    },
    creatingSession: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits<{
    createSession: [];
    selectSession: [session: SessionMeta];
    toggleCollapse: [];
    deleteSession: [session: SessionMeta];
    renameSession: [session: SessionMeta, newTitle: string];
}>();

const searchQuery = ref('');
const showDeleteDialog = ref(false);
const showRenameDialog = ref(false);
const selectedSession = ref<SessionMeta | null>(null);
const renameTitle = ref('');

const filteredSessions = computed(() => {
    if (!searchQuery.value.trim()) return props.sessions;
    const query = searchQuery.value.toLowerCase();
    return props.sessions.filter(
        (session) =>
            session.title?.toLowerCase().includes(query) ||
            session.derivedTitle?.toLowerCase().includes(query) ||
            session.friendlyId.toLowerCase().includes(query),
    );
});

function handleSelectSession(session: SessionMeta) {
    emit('selectSession', session);
}

function handleDeleteClick(session: SessionMeta, event: Event) {
    event.stopPropagation();
    selectedSession.value = session;
    showDeleteDialog.value = true;
}

function handleRenameClick(session: SessionMeta, event: Event) {
    event.stopPropagation();
    selectedSession.value = session;
    renameTitle.value = session.label || session.title || session.derivedTitle || '';
    showRenameDialog.value = true;
}

function confirmDelete() {
    if (selectedSession.value) {
        emit('deleteSession', selectedSession.value);
    }
    showDeleteDialog.value = false;
    selectedSession.value = null;
}

function confirmRename() {
    if (selectedSession.value && renameTitle.value.trim()) {
        emit('renameSession', selectedSession.value, renameTitle.value.trim());
    }
    showRenameDialog.value = false;
    selectedSession.value = null;
    renameTitle.value = '';
}

function getSessionTitle(session: SessionMeta): string {
    return session.label || session.title || session.derivedTitle || session.friendlyId;
}

function getLastMessagePreview(session: SessionMeta): string {
    if (!session.lastMessage) return '';
    const text = textFromMessage(session.lastMessage);
    return text.slice(0, 50) + (text.length > 50 ? '...' : '');
}
</script>

<template>
    <aside
        :class="
            cn(
                'border-r border-primary-200 dark:border-primary-700 h-full overflow-hidden bg-primary-100 dark:bg-primary-900 flex flex-col',
                'transition-all duration-200',
                isCollapsed ? 'w-12' : 'w-[300px]',
            )
        "
    >
        <!-- Header -->
        <div class="flex items-center h-12 px-2 justify-between">
            <template v-if="!isCollapsed">
                <button
                    type="button"
                    :class="
                        cn(
                            'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-lg text-sm font-medium transition-colors',
                            'text-primary-900 dark:text-primary-100 hover:bg-primary-200 dark:hover:bg-primary-800',
                            'w-full pl-1.5 justify-start',
                        )
                    "
                >
                    <img src="/img/lc_logo1.png" alt="LaraClaw" class="w-5 h-5 object-contain" />
                    <span class="overflow-hidden whitespace-nowrap">LaraClaw</span>
                </button>
            </template>

            <button
                type="button"
                :class="
                    cn(
                        'inline-flex items-center justify-center rounded-lg text-sm font-medium transition-colors',
                        'text-primary-900 dark:text-primary-100 hover:bg-primary-200 dark:hover:bg-primary-800',
                        'size-8',
                    )
                "
                @click="$emit('toggleCollapse')"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M11 19l-7-7 7-7M18 19l-7-7 7-7" />
                </svg>
            </button>
        </div>

        <!-- New Session Button -->
        <div class="px-2 mb-4 gap-px flex flex-col">
            <button
                type="button"
                :disabled="creatingSession"
                :class="
                    cn(
                        'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-lg text-sm font-medium transition-colors',
                        'text-primary-900 dark:text-primary-100 hover:bg-primary-200 dark:hover:bg-primary-800',
                        'w-full pl-1.5 justify-start',
                        creatingSession && 'opacity-50 cursor-not-allowed',
                    )
                "
                @click="$emit('createSession')"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M12 5v14M5 12h14" />
                </svg>
                <span v-if="!isCollapsed" class="overflow-hidden whitespace-nowrap">New Session</span>
            </button>
        </div>

        <!-- Sessions List -->
        <div v-if="!isCollapsed" class="flex-1 min-h-0 overflow-auto px-2">
            <div class="space-y-1">
                <div
                    v-for="session in filteredSessions"
                    :key="session.key"
                    :class="
                        cn(
                            'w-full text-left p-2 rounded-lg transition-colors cursor-pointer group',
                            session.friendlyId === activeFriendlyId
                                ? 'bg-primary-200 dark:bg-primary-800 text-primary-950 dark:text-primary-50'
                                : 'hover:bg-primary-200 dark:hover:bg-primary-800 text-primary-700 dark:text-primary-300',
                        )
                    "
                    @click="handleSelectSession(session)"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium truncate">
                                {{ getSessionTitle(session) }}
                            </div>
                            <div v-if="getLastMessagePreview(session)" class="text-xs text-primary-500 dark:text-primary-400 truncate mt-0.5">
                                {{ getLastMessagePreview(session) }}
                            </div>
                        </div>
                        <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            <button
                                type="button"
                                class="p-1 hover:bg-primary-300 dark:hover:bg-primary-700 rounded"
                                @click="handleRenameClick(session, $event)"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                                </svg>
                            </button>
                            <button
                                type="button"
                                class="p-1 hover:bg-red-100 dark:hover:bg-red-900/30 text-red-600 dark:text-red-400 rounded"
                                @click="handleDeleteClick(session, $event)"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delete Dialog -->
        <Teleport to="body">
            <div
                v-if="showDeleteDialog"
                class="fixed inset-0 z-50 bg-black/40"
                @click="showDeleteDialog = false"
            >
                <div
                    class="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[min(400px,92vw)] rounded-[20px] border border-primary-200 dark:border-primary-700 bg-primary-50 dark:bg-primary-900 p-6 shadow-lg"
                    @click.stop
                >
                    <h3 class="text-lg font-medium text-primary-900 dark:text-primary-100 mb-2">Delete Session</h3>
                    <p class="text-sm text-primary-600 dark:text-primary-300 mb-4">
                        Are you sure you want to delete "{{ selectedSession ? getSessionTitle(selectedSession) : '' }}"?
                    </p>
                    <div class="flex justify-end gap-2">
                        <Button variant="outline" @click="showDeleteDialog = false">Cancel</Button>
                        <Button variant="destructive" @click="confirmDelete">Delete</Button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Rename Dialog -->
        <Teleport to="body">
            <div
                v-if="showRenameDialog"
                class="fixed inset-0 z-50 bg-black/40"
                @click="showRenameDialog = false"
            >
                <div
                    class="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[min(400px,92vw)] rounded-[20px] border border-primary-200 dark:border-primary-700 bg-primary-50 dark:bg-primary-900 p-6 shadow-lg"
                    @click.stop
                >
                    <h3 class="text-lg font-medium text-primary-900 dark:text-primary-100 mb-2">Rename Session</h3>
                    <input
                        v-model="renameTitle"
                        type="text"
                        class="w-full h-9 px-3 rounded-lg border border-primary-200 dark:border-primary-600 bg-surface dark:bg-primary-800 text-primary-900 dark:text-primary-100 outline-none focus:border-primary-500"
                        @keydown.enter="confirmRename"
                    />
                    <div class="flex justify-end gap-2 mt-4">
                        <Button variant="outline" @click="showRenameDialog = false">Cancel</Button>
                        <Button variant="default" @click="confirmRename">Save</Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </aside>
</template>

import { defineStore } from 'pinia';
import { ref, watch, computed } from 'vue';
import type { ChatSettings, ThemeMode, ThinkingLevel } from '@/types/chat';

const STORAGE_KEY = 'laraclaw-chat-settings';

function loadSettings(): ChatSettings {
    if (typeof window === 'undefined') {
        return {
            showToolMessages: true,
            showReasoningBlocks: true,
            thinkingLevel: 'medium',
            theme: 'system',
        };
    }

    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) {
            const parsed = JSON.parse(stored);
            return {
                showToolMessages: parsed.showToolMessages ?? true,
                showReasoningBlocks: parsed.showReasoningBlocks ?? true,
                thinkingLevel: parsed.thinkingLevel ?? 'medium',
                theme: parsed.theme ?? 'system',
            };
        }
    } catch {
        // ignore
    }

    return {
        showToolMessages: true,
        showReasoningBlocks: true,
        thinkingLevel: 'medium',
        theme: 'system',
    };
}

export const useChatSettingsStore = defineStore('chatSettings', () => {
    const settings = ref<ChatSettings>(loadSettings());

    // Persist settings to localStorage
    watch(
        settings,
        (newSettings) => {
            if (typeof window !== 'undefined') {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(newSettings));
            }
        },
        { deep: true },
    );

    function updateSettings(updates: Partial<ChatSettings>) {
        settings.value = { ...settings.value, ...updates };
    }

    function setTheme(theme: ThemeMode) {
        updateSettings({ theme });
        applyTheme(theme);
    }

    function setThinkingLevel(thinkingLevel: ThinkingLevel) {
        updateSettings({ thinkingLevel });
    }

    function toggleToolMessages() {
        updateSettings({ showToolMessages: !settings.value.showToolMessages });
    }

    function toggleReasoningBlocks() {
        updateSettings({ showReasoningBlocks: !settings.value.showReasoningBlocks });
    }

    return {
        settings,
        updateSettings,
        setTheme,
        setThinkingLevel,
        toggleToolMessages,
        toggleReasoningBlocks,
    };
});

// Theme application helper
export function applyTheme(theme: ThemeMode) {
    if (typeof window === 'undefined') return;

    const root = document.documentElement;
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    
    console.log('applyTheme called with theme:', theme);

    function apply() {
        // Remove all theme classes first
        root.classList.remove('light', 'dark', 'system');
        
        console.log('Applying theme:', theme);
        
        if (theme === 'dark') {
            root.classList.add('dark');
            console.log('Added dark class, classes:', root.classList.toString());
        } else if (theme === 'light') {
            root.classList.add('light');
            console.log('Added light class, classes:', root.classList.toString());
        } else {
            // System theme - add 'system' class and conditionally add 'dark'
            root.classList.add('system');
            if (media.matches) {
                root.classList.add('dark');
            }
            console.log('Added system class, classes:', root.classList.toString());
        }
    }

    apply();

    // Listen for system theme changes (only relevant for 'system' mode)
    const handler = () => {
        if (theme === 'system') {
            apply();
        }
    };
    
    // Remove existing listener first to avoid duplicates
    media.removeEventListener('change', handler);
    media.addEventListener('change', handler);
}

// Composable for resolved theme
export function useResolvedTheme() {
    const store = useChatSettingsStore();
    const systemIsDark = ref(false);

    if (typeof window !== 'undefined') {
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        systemIsDark.value = media.matches;

        media.addEventListener('change', (e) => {
            systemIsDark.value = e.matches;
        });
    }

    return computed(() => {
        if (store.settings.theme === 'dark') return 'dark';
        if (store.settings.theme === 'light') return 'light';
        return systemIsDark.value ? 'dark' : 'light';
    });
}

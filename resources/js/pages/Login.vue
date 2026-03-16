<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import Button from '@/components/ui/Button.vue';
import { useAuthStore } from '@/stores/auth';
import { useChatSettingsStore, applyTheme } from '@/stores/chatSettings';

const authStore = useAuthStore();
const settingsStore = useChatSettingsStore();

// Track current resolved theme for icon display
const isDark = ref(false);

onMounted(() => {
    // Apply theme on mount
    applyTheme(settingsStore.settings.theme);
    updateThemeIcon();
    
    // Listen for system theme changes
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    media.addEventListener('change', updateThemeIcon);
});

function updateThemeIcon() {
    const theme = settingsStore.settings.theme;
    if (theme === 'dark') {
        isDark.value = true;
    } else if (theme === 'light') {
        isDark.value = false;
    } else {
        // System theme
        isDark.value = window.matchMedia('(prefers-color-scheme: dark)').matches;
    }
}

function toggleTheme() {
    // Simple toggle between light and dark
    const newTheme = isDark.value ? 'light' : 'dark';
    settingsStore.setTheme(newTheme);
    // Update isDark based on the new theme
    isDark.value = newTheme === 'dark';
}

const apiToken = ref('');
const error = ref('');
const isLoading = ref(false);

async function handleLogin() {
    if (!apiToken.value.trim()) {
        error.value = 'Please enter your API token';
        return;
    }

    isLoading.value = true;
    error.value = '';

    try {
        // Get WebSocket configuration from meta tags
        const wsHost = document.querySelector('meta[name="laraclaw-ws-host"]')?.getAttribute('content') || 'localhost';
        const wsPort = document.querySelector('meta[name="laraclaw-ws-port"]')?.getAttribute('content') || '19123';

        // Attempt to connect to WebSocket server with the token
        const validationResult = await validateTokenWithWebSocket(wsHost, parseInt(wsPort), apiToken.value.trim());

        if (validationResult.valid) {
            // Store the token
            authStore.setToken(apiToken.value.trim());
            // Redirect to chat
            router.visit('/chat');
        } else {
            error.value = validationResult.error || 'Invalid API token';
        }
    } catch (err) {
        error.value = 'Failed to connect to server. Please ensure the LaraClaw server is running.';
        console.error('Login error:', err);
    } finally {
        isLoading.value = false;
    }
}

function validateTokenWithWebSocket(host: string, port: number, token: string): Promise<{ valid: boolean; error?: string }> {
    return new Promise((resolve) => {
        const ws = new WebSocket(`ws://${host}:${port}`);
        let resolved = false;

        const cleanup = () => {
            if (!resolved) {
                resolved = true;
                ws.close();
            }
        };

        // Timeout after 10 seconds
        const timeout = setTimeout(() => {
            cleanup();
            resolve({ valid: false, error: 'Connection timeout' });
        }, 10000);

        ws.onopen = () => {
            // Send authentication request
            ws.send(JSON.stringify({
                type: 'auth',
                token: token,
            }));
        };

        ws.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                
                if (data.type === 'auth_success') {
                    clearTimeout(timeout);
                    cleanup();
                    resolve({ valid: true });
                } else if (data.type === 'auth_failed' || data.type === 'error') {
                    clearTimeout(timeout);
                    cleanup();
                    resolve({ valid: false, error: data.message || 'Authentication failed' });
                }
            } catch {
                // Ignore parse errors for non-JSON messages
            }
        };

        ws.onerror = () => {
            clearTimeout(timeout);
            cleanup();
            resolve({ valid: false, error: 'Failed to connect to server' });
        };

        ws.onclose = () => {
            clearTimeout(timeout);
            if (!resolved) {
                resolve({ valid: false, error: 'Connection closed unexpectedly' });
            }
        };
    });
}
</script>

<template>
    <Head title="Login">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>
    <div class="min-h-screen flex items-center justify-center bg-primary-50 dark:bg-primary-950">
        <!-- Theme Toggle Button -->
        <button
            type="button"
            class="absolute top-4 right-4 p-2 rounded-lg bg-primary-100 dark:bg-primary-800 hover:bg-primary-200 dark:hover:bg-primary-700 text-primary-600 dark:text-primary-300 transition-colors"
            :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
            @click="toggleTheme"
        >
            <!-- Sun icon (shown in dark mode) -->
            <svg v-if="isDark" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <circle cx="12" cy="12" r="5" />
                <line x1="12" y1="1" x2="12" y2="3" />
                <line x1="12" y1="21" x2="12" y2="23" />
                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" />
                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" />
                <line x1="1" y1="12" x2="3" y2="12" />
                <line x1="21" y1="12" x2="23" y2="12" />
                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" />
                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" />
            </svg>
            <!-- Moon icon (shown in light mode) -->
            <svg v-else xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
            </svg>
        </button>
        <div class="w-full max-w-md p-8">
            <!-- Logo/Title -->
            <div class="text-center mb-8">
                <img src="/img/lc_logo2.png" alt="LaraClaw Logo" class="mx-auto mb-4" style="width: 100px; height: 100px;" />
                <h1 class="text-xl font-bold text-primary-900 dark:text-primary-100">LaraClaw</h1>
                <p class="text-primary-600 dark:text-primary-400 mt-2">Enter your API token log in</p>
            </div>

            <!-- Login Form -->
            <form @submit.prevent="handleLogin" class="space-y-6">
                <div>
                    <label for="api-token" class="block text-sm font-medium text-primary-700 dark:text-primary-300 mb-2">
                        API Token
                    </label>
                    <input
                        id="api-token"
                        v-model="apiToken"
                        type="password"
                        :disabled="isLoading"
                        class="w-full px-4 py-3 rounded-lg border border-primary-200 dark:border-primary-700 bg-white dark:bg-primary-900 text-primary-900 dark:text-primary-100 placeholder-primary-400 dark:placeholder-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent transition-colors"
                        placeholder="Enter your API token"
                        autocomplete="off"
                    />
                </div>

                <!-- Error Message -->
                <div v-if="error" class="p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800">
                    <p class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
                </div>

                <!-- Submit Button -->
                <Button
                    type="submit"
                    :disabled="isLoading"
                    class="w-full py-3"
                >
                    <span v-if="isLoading" class="flex items-center justify-center gap-2">
                        <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Connecting...
                    </span>
                    <span v-else>Connect</span>
                </Button>
            </form>

            <!-- Help Text -->
            <p class="mt-6 text-center text-sm text-primary-500 dark:text-primary-400">
                The API token is defined in your <code class="px-1 py-0.5 rounded bg-primary-100 dark:bg-primary-800 text-primary-700 dark:text-primary-300">.env</code> file as <code class="px-1 py-0.5 rounded bg-primary-100 dark:bg-primary-800 text-primary-700 dark:text-primary-300">LARACLAW_SERVER_API_KEY</code>
            </p>
        </div>
    </div>
</template>
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

export const useAuthStore = defineStore('auth', () => {
    const token = ref<string | null>(localStorage.getItem('laraclaw_token'));
    const isAuthenticated = computed(() => !!token.value);

    function setToken(newToken: string) {
        token.value = newToken;
        localStorage.setItem('laraclaw_token', newToken);
    }

    function clearToken() {
        token.value = null;
        localStorage.removeItem('laraclaw_token');
    }

    function logout() {
        clearToken();
    }

    return {
        token,
        isAuthenticated,
        setToken,
        clearToken,
        logout,
    };
});

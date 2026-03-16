import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import '../css/app.css';

const appName = import.meta.env.VITE_APP_NAME || 'LaraClaw';

const pinia = createPinia();

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(pinia)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// Apply theme on load
const themeScript = `
(() => {
    try {
        const stored = localStorage.getItem('laraclaw-chat-settings')
        let theme = 'system'
        if (stored) {
            const parsed = JSON.parse(stored)
            const storedTheme = parsed?.theme
            if (storedTheme === 'light' || storedTheme === 'dark' || storedTheme === 'system') {
                theme = storedTheme
            }
        }
        const root = document.documentElement
        const media = window.matchMedia('(prefers-color-scheme: dark)')
        const apply = () => {
            root.classList.remove('light', 'dark', 'system')
            if (theme === 'dark') {
                root.classList.add('dark')
            } else if (theme === 'light') {
                root.classList.add('light')
            } else {
                root.classList.add('system')
                if (media.matches) {
                    root.classList.add('dark')
                }
            }
        }
        apply()
        media.addEventListener('change', () => {
            if (theme === 'system') apply()
        })
    } catch {}
})()
`;

const script = document.createElement('script');
script.textContent = themeScript;
document.head.appendChild(script);

import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

function applyTheme(theme) {
    const root = document.documentElement;
    if (theme === 'dark') {
        root.classList.add('dark');
    } else {
        root.classList.remove('dark');
    }
    try {
        localStorage.setItem('theme', theme === 'dark' ? 'dark' : 'light');
    } catch (e) {}
}

function syncFromPage(page) {
    const theme = page?.props?.auth?.user?.theme;
    if (theme === 'dark' || theme === 'light') {
        applyTheme(theme);
    }
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        syncFromPage(props.initialPage);
        router.on('navigate', (event) => syncFromPage(event.detail.page));

        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// El ?v= saltea copias viejas de sw.js en el CDN; no hace falta subirlo en cada
// deploy porque .htaccess lo sirve con no-cache.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js?v=1').catch(() => {});
    });
}

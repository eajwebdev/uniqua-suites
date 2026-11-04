import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

// Page changes here happen without a reload, so screen reader and keyboard users are moved
// to the new content. Filters that stay on the same page keep focus where it is.
let currentPath = window.location.pathname;
router.on('navigate', (event) => {
    const path = new URL(event.detail.page.url, window.location.origin).pathname;
    if (path !== currentPath) {
        currentPath = path;
        requestAnimationFrame(() => document.getElementById('main')?.focus({ preventScroll: true }));
    }
});

createInertiaApp({
    title: (title) => (title ? `${title} | UNIQA SUITES` : 'UNIQA SUITES'),
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')) as any,
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#c6a675' },
});

import '../css/app.css';

import { LayoutProvider } from '@/contexts/page-context';
import { ProcessingProvider } from '@/contexts/processing-context';
import { initializeTheme } from '@/hooks/use-appearance';
import { initializeTheme as initializeVisualTheme } from '@/hooks/use-theme';
import { applyLayoutToPage } from '@/lib/layout-resolver';
import { createInertiaApp } from '@inertiajs/react';
import { configureEcho } from '@laravel/echo-react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot, hydrateRoot } from 'react-dom/client';
import { I18nextProvider } from 'react-i18next';
import { initI18n, setLocale } from './i18n';

// Reverb connection params come from VITE_REVERB_* env vars (set by the
// laravel-vite-plugin so the JS bundle picks them up at build time). Without
// these, Echo falls back to Pusher defaults (ws-mt1.pusher.com:443) and
// silently fails to connect - observable as "sync spinner never ends" and
// missing completion toasts.
configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

const appName = import.meta.env.VITE_APP_NAME || 'CRM Solo';

void createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.{tsx,ts}');

        // Try folder structure first (e.g., ./pages/dashboard/index.ts)
        let pagePath = `./pages/${name}/index.ts`;
        if (!pages[pagePath]) {
            // Fallback to direct file (e.g., ./pages/auth/login.tsx)
            pagePath = `./pages/${name}.tsx`;
        }

        const page = resolvePageComponent(pagePath, pages);

        page.then((module) => {
            applyLayoutToPage(module, name);
        });

        return page;
    },
    setup({ el, App, props }) {
        const currentLocale = props.initialPage.props.locale;
        const i18nInstance = initI18n(currentLocale, props.initialPage.props.translations || {});
        setLocale(currentLocale);

        const AppWithProviders = (
            <LayoutProvider>
                <ProcessingProvider>
                    <I18nextProvider i18n={i18nInstance}>
                        <App {...props} />
                    </I18nextProvider>
                </ProcessingProvider>
            </LayoutProvider>
        );

        if (import.meta.env.SSR) {
            hydrateRoot(el, AppWithProviders);
            return;
        }

        createRoot(el).render(AppWithProviders);
    },
    progress: {
        color: '#4B5563',
    },
});

// Both run at module scope so appearance and visual theme land before the
// first paint, rather than flashing the default and correcting after mount.
initializeTheme();
initializeVisualTheme();

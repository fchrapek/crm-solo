import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig, loadEnv } from 'vite';

// The dev server follows APP_URL. https (the documented crm-solo.test route
// through DDEV's traefik): assets and wss HMR on that hostname. Plain http
// (a fresh clone, APP_URL=http://127.0.0.1:8101): plain http and ws straight
// to Vite on 127.0.0.1. VITE_PORT moves the port; 5180 by default.
export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const appUrl = new URL(env.APP_URL || 'http://127.0.0.1:8101');
    const secure = appUrl.protocol === 'https:';
    const host = '127.0.0.1';
    const port = Number(env.VITE_PORT || 5180);
    const publicHost = secure ? appUrl.hostname : host;

    return {
        plugins: [
            laravel({
                input: 'resources/js/app.tsx',
                ssr: 'resources/js/ssr.tsx',
                refresh: true,
                detectTls: false,
            }),
            react(),
            wayfinder(),
        ],
        esbuild: {
            jsx: 'automatic',
        },
        // 5180 instead of Vite's default 5173 — every WP client project's theme
        // Vite (Sage, wp-solo, …) defaults to 5173, and the 5174 fallback already
        // gets claimed by sibling projects (ads-solo, …). Pick a port nothing else
        // is reaching for so task-preview Start can boot DDEV without colliding
        // with the CRM's own dev server.
        server: {
            host,
            port,
            strictPort: true,
            origin: `${secure ? 'https' : 'http'}://${publicHost}:${port}`,
            cors: {
                origin: appUrl.origin,
            },
            hmr: {
                protocol: secure ? 'wss' : 'ws',
                host: publicHost,
                clientPort: port,
            },
        },
        ssr: {
            noExternal: true,
        },
        build: {
            rollupOptions: {
                output: {
                    manualChunks: {
                        // Core React - changes rarely
                        'vendor-react': ['react', 'react-dom'],

                        // Radix UI components - changes rarely
                        'vendor-radix': [
                            '@radix-ui/react-alert-dialog',
                            '@radix-ui/react-avatar',
                            '@radix-ui/react-checkbox',
                            '@radix-ui/react-collapsible',
                            '@radix-ui/react-dialog',
                            '@radix-ui/react-dropdown-menu',
                            '@radix-ui/react-label',
                            '@radix-ui/react-navigation-menu',
                            '@radix-ui/react-popover',
                            '@radix-ui/react-scroll-area',
                            '@radix-ui/react-select',
                            '@radix-ui/react-separator',
                            '@radix-ui/react-slot',
                            '@radix-ui/react-toggle',
                            '@radix-ui/react-toggle-group',
                            '@radix-ui/react-tooltip',
                        ],

                        // Inertia - the bridge between Laravel and React
                        'vendor-inertia': ['@inertiajs/react'],

                        // Charts library - only needed on pages with charts
                        'vendor-charts': ['recharts'],

                        // i18n - internationalization
                        'vendor-i18n': ['i18next', 'react-i18next', 'i18next-browser-languagedetector', 'i18next-http-backend'],

                        // Real-time features
                        'vendor-realtime': ['laravel-echo', 'pusher-js'],

                        // Icons
                        'vendor-icons': ['lucide-react'],
                    },
                },
            },
        },
    };
});

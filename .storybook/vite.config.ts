import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

// Not the app's config: its Laravel and Wayfinder plugins need PHP and a backend, a story needs only React and the alias.
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: { '@': fileURLToPath(new URL('../resources/js', import.meta.url)) },
    },
});

import type { StorybookConfig } from '@storybook/react-vite';

const config: StorybookConfig = {
    stories: ['../resources/js/**/*.stories.tsx'],
    framework: {
        name: '@storybook/react-vite',
        options: { builder: { viteConfigPath: '.storybook/vite.config.ts' } },
    },
    core: { disableTelemetry: true },
};

export default config;

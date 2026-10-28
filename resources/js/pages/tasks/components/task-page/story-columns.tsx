import type { Decorator } from '@storybook/react-vite';

/** The record split's widths at 1440 (760 + 380), so a section renders as wide as on the page. */
export const mainColumn: Decorator = (Story) => (
    <div style={{ maxWidth: 760 }}>
        <Story />
    </div>
);

export const sideColumn: Decorator = (Story) => (
    <div style={{ maxWidth: 380 }}>
        <Story />
    </div>
);

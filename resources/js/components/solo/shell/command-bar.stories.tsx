import type { Meta, StoryObj } from '@storybook/react-vite';
import { CommandBar } from './command-bar';

const meta = {
    title: 'Redesign/Command bar',
    component: CommandBar,
} satisfies Meta<typeof CommandBar>;

export default meta;

type Story = StoryObj<typeof meta>;

/** A section's home: nothing above it, so no tokens. */
export const Empty: Story = {};

/** One token per level below the section; the last is the page, the ones before it are links. Click the bar to search. */
export const Tokens: Story = {
    args: {
        tokens: [{ label: 'Pracownia Mebli', href: '#' }, { label: 'Rozliczenia', href: '#' }, { label: 'Wrzesień' }],
    },
};

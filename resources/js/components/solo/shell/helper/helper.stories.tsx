import type { Meta, StoryObj } from '@storybook/react-vite';
import { Helper } from './helper';

const DESKTOP = { viewport: { value: 'desktop', isRotated: false } };

const meta = {
    title: 'Redesign/Helper',
    component: Helper,
    args: { page: 'clients/index', props: {} },
} satisfies Meta<typeof Helper>;

export default meta;

type Story = StoryObj<typeof meta>;

/** A mock: the dog alone on a page without a hint. On a phone it is a button that opens the window as a sheet. */
export const Closed: Story = {};

/** Dziś with free slots: one line and one action, from 768 px. The dog looks at the line. */
export const Hint: Story = { globals: DESKTOP, args: { page: 'today/index', props: { state: 'paper', maxPicks: 5, picks: [1, 2] } } };

/** A client over the package: the figure comes from the page's own props, and the dog is surprised. */
export const Warning: Story = {
    globals: DESKTOP,
    args: { page: 'clients/show', props: { tab: 'przeglad', client: { id: 7, short_name: 'Pracownia Mebli' }, overview: { hours: { remaining: -12.8 } } } },
};

export const ClosedDesktop: Story = { globals: DESKTOP };

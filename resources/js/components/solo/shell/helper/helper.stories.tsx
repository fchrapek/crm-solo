import type { Meta, StoryObj } from '@storybook/react-vite';
import { Helper } from './helper';

const DESKTOP = { viewport: { value: 'desktop', isRotated: false } };
const CLIENT = { tab: 'overview', client: { id: 7, short_name: 'Pracownia Mebli' } };

const meta = {
    title: 'Redesign/Helper',
    component: Helper,
    args: { page: 'today/index', url: '/', props: { state: 'paper', maxPicks: 5, picks: [1, 2, 3, 4, 5] } },
} satisfies Meta<typeof Helper>;

export default meta;

type Story = StoryObj<typeof meta>;

/** The phone button above the timer strip (the tab bar without one); it opens the screen's topics as a sheet. */
export const Closed: Story = {};

export const ClosedDesktop: Story = { globals: DESKTOP };

/** Today with free slots: pointed at or focused, the dog shows one line from the page and the topic it leads to. */
export const Hint: Story = { globals: DESKTOP, args: { props: { state: 'paper', maxPicks: 5, picks: [1, 2] } } };

/** A client over the package: the figure comes from the page's own props, and the dog is surprised. */
export const Warning: Story = { globals: DESKTOP, args: { page: 'clients/show', url: '/clients/7', props: { ...CLIENT, overview: { hours: { remaining: -12.8 } } } } };

/** With the docs site set, the list and every answer link to it. */
export const WithDocs: Story = { args: { page: 'tasks/show', url: '/tasks/1', props: {}, docsUrl: 'https://docs.example.com' } };

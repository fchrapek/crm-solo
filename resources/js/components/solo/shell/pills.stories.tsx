import type { Meta, StoryObj } from '@storybook/react-vite';
import { Pills } from './pills';

const meta = {
    title: 'Redesign/Pills',
    component: Pills,
    args: {
        items: [
            { label: 'Dziś', href: '/', active: true },
            { label: 'Podsumowanie', href: '/?view=summary' },
            { label: 'Jutro', href: '/tomorrow' },
        ],
    },
} satisfies Meta<typeof Pills>;

export default meta;

type Story = StoryObj<typeof meta>;

/** The siblings of the last place level; exactly one is selected. */
export const DayViews: Story = {};

/** A count is optional; on a phone a long row scrolls sideways. */
export const WithCounts: Story = {
    args: {
        items: [
            { label: 'Przegląd', href: '#' },
            { label: 'Zadania', href: '#', count: 25, active: true },
            { label: 'Dziennik', href: '#', count: 4 },
            { label: 'Rozliczenia', href: '#' },
            { label: 'Dane', href: '#' },
        ],
    },
};

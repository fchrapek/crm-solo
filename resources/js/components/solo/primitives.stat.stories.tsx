import type { Meta, StoryObj } from '@storybook/react-vite';
import { Stat, StatRow } from './primitives';

const meta = {
    title: 'Solo/Stat',
    component: Stat,
    args: { value: '2 h 52', label: 'zalogowane' },
} satisfies Meta<typeof Stat>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Hours: Story = {};

export const Minutes: Story = { args: { value: '38 min', label: 'dziś' } };

export const Count: Story = { args: { value: '3 z 5', label: 'zadania' } };

/** Podsumowanie: three stats on the Done surface, spread over a phone's width. */
export const RowOfThree: Story = {
    globals: { day: 'done' },
    render: () => (
        <StatRow fill>
            <Stat value="3 z 5" label="zadania" />
            <Stat value="2 h 52" label="zalogowane" />
            <Stat value={2} label="klientów" />
        </StatRow>
    ),
};

/** Zadanie and Zaloguj czas on phones: dziś and łącznie on Paper. */
export const RowOfTwo: Story = {
    render: () => (
        <StatRow>
            <Stat value="38 min" label="dziś" />
            <Stat value="2 h 52" label="łącznie" />
        </StatRow>
    ),
};

export const OnTimer: Story = { args: { value: '4 h 05', label: 'dziś łącznie' }, globals: { day: 'timer' } };

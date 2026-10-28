import type { Meta, StoryObj } from '@storybook/react-vite';
import { DoneList } from './index';
import { picks } from './story-fixtures';

/** Figma: Podsumowanie, the picks as Done rows in the accent block. */
const meta = {
    title: 'Dziś/Podsumowanie list',
    component: DoneList,
    args: { picks, timers: [] },
    globals: { day: 'done' },
    decorators: [
        (Story) => (
            <div style={{ maxWidth: 760 }}>
                <Story />
            </div>
        ),
    ],
} satisfies Meta<typeof DoneList>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Phone: Story = {};

export const Desktop: Story = { globals: { day: 'done', viewport: { value: 'desktop', isRotated: false } } };

export const AllDone: Story = { args: { picks: picks.slice(0, 2) } };

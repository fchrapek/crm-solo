import type { Meta, StoryObj } from '@storybook/react-vite';
import { Tile } from '@/components/solo/tile';
import { PlanRows } from './summary';
import { picks } from './story-fixtures';

/** Figma: Summary, the day's picks in the plan tile. */
const meta = {
    title: 'Today/Summary list',
    component: PlanRows,
    args: { picks, timers: [] },
    decorators: [
        (Story) => (
            <Tile kind="list" style={{ maxWidth: 640 }}>
                <Story />
            </Tile>
        ),
    ],
} satisfies Meta<typeof PlanRows>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Phone: Story = {};

export const Desktop: Story = { globals: { viewport: { value: 'desktop', isRotated: false } } };

export const AllDone: Story = { args: { picks: picks.slice(0, 2) } };

import type { Meta, StoryObj } from '@storybook/react-vite';
import { TimerQueue } from './index';
import { picks } from './story-fixtures';

/** Figma: Timer A2, the side column: the day's picks as Queue rows on the timer surface. */
const meta = {
    title: 'Dziś/Timer queue',
    component: TimerQueue,
    args: { picks, runningTaskId: 44 },
    globals: { day: 'timer' },
    decorators: [
        (Story) => (
            <div style={{ maxWidth: 380 }}>
                <Story />
            </div>
        ),
    ],
} satisfies Meta<typeof TimerQueue>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Phone: Story = {};

export const Desktop: Story = { globals: { day: 'timer', viewport: { value: 'desktop', isRotated: false } } };

/** A timer on a task outside the picks: no row is current, the first open one is next. */
export const OffPlanTimer: Story = { args: { runningTaskId: 99 } };

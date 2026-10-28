import type { Meta, StoryObj } from '@storybook/react-vite';
import { DayStatus } from './index';

/** Figma: A2f / Day status, under the month card on Podsumowanie (Surface=Done). */
const meta = {
    title: 'Solo/Day status',
    component: DayStatus,
    args: { closed: true, running: false },
    globals: { day: 'done' },
    decorators: [
        (Story) => (
            <div style={{ maxWidth: 380 }}>
                <Story />
            </div>
        ),
    ],
} satisfies Meta<typeof DayStatus>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Closed: Story = {};

export const Open: Story = { args: { closed: false } };

/** Open, with a timer that keeps the day from closing. */
export const OpenWithTimer: Story = { args: { closed: false, running: true } };

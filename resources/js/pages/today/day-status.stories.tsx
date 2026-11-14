import type { Meta, StoryObj } from '@storybook/react-vite';
import { DayStatus } from './summary';

/** Figma: Podsumowanie, the day's state beside the close button. */
const meta = {
    title: 'Dziś/Podsumowanie status',
    component: DayStatus,
    args: { closed: true, running: false },
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

import type { Meta, StoryObj } from '@storybook/react-vite';
import { TimerChip } from './rail';
import { TimerStrip } from './timer-strip';

const timer = { id: 1, started_at: new Date(Date.now() - 72 * 60_000).toISOString(), task: 'Korekta faktury 14/09: dane odbiorcy', client: 'Fundacja Testowa' };

const meta = {
    title: 'Redesign/Timer strip',
    component: TimerStrip,
    args: { timer },
} satisfies Meta<typeof TimerStrip>;

export default meta;

type Story = StoryObj<typeof meta>;

/** Phones only: fixed above the tab bar while a timer runs. */
export const Strip: Story = {};

/** The same timer in the rail from 768 px; one click stops it. */
export const RailChip: Story = { globals: { viewport: { value: 'desktop', isRotated: false } }, render: () => <TimerChip timer={timer} /> };

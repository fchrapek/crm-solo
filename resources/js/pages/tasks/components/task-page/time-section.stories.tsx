import type { Meta, StoryObj } from '@storybook/react-vite';
import { sideColumn } from './story-columns';
import { NOW, runningTime, time } from './story-fixtures';
import { TimeSection, TimeStats } from './time-section';

const ago = (minutes: number) => new Date(NOW - minutes * 60_000).toISOString();

const meta = {
    title: 'Zadania/Zadanie/Czas',
    component: TimeSection,
    decorators: [sideColumn],
    args: { task: { id: 42, name: 'Aktualizacja cennika PDF', client: 'Pracownia Mebli' }, time, now: NOW },
} satisfies Meta<typeof TimeSection>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Idle: Story = {};

export const Running: Story = { args: { time: runningTime } };

/** The widest running label at 380: over an hour on the timer, the clock form. */
export const RunningOverAnHour: Story = { args: { time: { ...runningTime, own_running: { id: 9, started_at: ago(63) }, running: [ago(63)] } } };

/** Phones: the Stat row under the meta in place of the Czas block, running timers counted. */
export const PhoneStats: Story = { render: (args) => <TimeStats time={args.time} now={args.now} />, args: { time: runningTime } };

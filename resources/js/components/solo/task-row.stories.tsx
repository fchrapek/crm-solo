import type { Meta, StoryObj } from '@storybook/react-vite';
import { SoloButton } from './primitives';
import { type DayTask, TaskRow } from './task-row';

const open: DayTask = { task_id: 42, name: 'Aktualizacja cennika PDF', client: 'Pracownia Mebli', project: 'Strona WWW', done: false };

const noop = () => {};

const meta = {
    title: 'Zadania/Task row',
    component: TaskRow,
    args: { task: open },
    decorators: [
        (Story) => (
            <ul style={{ listStyle: 'none', margin: 0, padding: 0, maxWidth: 760 }}>
                <Story />
            </ul>
        ),
    ],
} satisfies Meta<typeof TaskRow>;

export default meta;

type Story = StoryObj<typeof meta>;

export const OnTheList: Story = {};

export const Open: Story = { args: { onComplete: noop } };

export const Done: Story = { args: { task: { ...open, done: true, can_untick: true }, onComplete: noop, onUncomplete: noop } };

export const DoneOnTheBoard: Story = { args: { task: { ...open, done: true, can_untick: false }, onComplete: noop } };

export const WithDue: Story = { args: { kind: 'client', due: 'do 28.10' } };

export const Compact: Story = { args: { compact: true, onComplete: noop } };

export const LongTitle: Story = {
    args: { task: { ...open, name: 'Korekta faktury 14/09: dane odbiorcy, adres korespondencyjny i numer zamówienia' }, onComplete: noop },
};

export const WithStart: Story = {
    args: {
        onComplete: noop,
        trailing: (
            <SoloButton variant="text" size="sm">
                Start
            </SoloButton>
        ),
    },
};

export const Desktop: Story = { ...WithStart, globals: { viewport: { value: 'desktop', isRotated: false } } };

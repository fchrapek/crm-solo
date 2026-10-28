import type { Meta, StoryObj } from '@storybook/react-vite';
import { type DayTask, TaskRow } from './task-row';

const pick: DayTask = { id: 2, task_id: 43, name: 'Nowa galeria realizacji', client: 'Pracownia Mebli', project: 'Strona WWW', done: false };

const noop = () => {};

/** Figma: A2f / Queue row in the timer's side column (Surface=Timer). */
const meta = {
    title: 'Zadania/Queue row',
    component: TaskRow,
    args: { kind: 'queue', task: pick, onComplete: noop, onUncomplete: noop },
    globals: { day: 'timer' },
    decorators: [
        (Story) => (
            <ul
                style={{
                    listStyle: 'none',
                    margin: 0,
                    padding: 'var(--solo-block-pad)',
                    borderRadius: 'var(--solo-block-radius)',
                    background: 'var(--solo-block)',
                    maxWidth: 380,
                }}
            >
                <Story />
            </ul>
        ),
    ],
} satisfies Meta<typeof TaskRow>;

export default meta;

type Story = StoryObj<typeof meta>;

/** State=Done: ticked, with its duration. */
export const Done: Story = { args: { task: { ...pick, done: true, can_untick: true, minutes: 52 }, trailing: '0 h 52' } };

/** State=Current: the task the timer runs on, on the tint. */
export const Current: Story = { args: { current: true, trailing: 'w toku' } };

export const Next: Story = { args: { trailing: 'następne' } };

/** State=Open: no status. */
export const Open: Story = {};

export const Every: Story = {
    render: () => (
        <>
            <TaskRow
                kind="queue"
                task={{ ...pick, task_id: 41, name: 'Aktualizacja cennika PDF', done: true, can_untick: true }}
                trailing="0 h 52"
                onComplete={noop}
                onUncomplete={noop}
            />
            <TaskRow kind="queue" current task={pick} trailing="w toku" onComplete={noop} />
            <TaskRow kind="queue" task={{ ...pick, task_id: 44, name: 'Formularz kontaktowy' }} trailing="następne" onComplete={noop} />
            <TaskRow kind="queue" task={{ ...pick, task_id: 45, name: 'Raport miesięczny dla Fundacji Testowej' }} onComplete={noop} />
        </>
    ),
};

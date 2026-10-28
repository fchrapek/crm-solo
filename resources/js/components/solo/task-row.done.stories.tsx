import type { Meta, StoryObj } from '@storybook/react-vite';
import { type DayTask, TaskRow } from './task-row';

const done: DayTask = {
    id: 1,
    task_id: 42,
    name: 'Aktualizacja cennika PDF',
    client: 'Pracownia Mebli',
    project: 'Strona WWW',
    done: true,
    can_untick: true,
    minutes: 68,
};

const noop = () => {};

/** Figma: A2f / Done row in the Accent block on Podsumowanie (Surface=Done). */
const meta = {
    title: 'Zadania/Done row',
    component: TaskRow,
    args: { kind: 'done', task: done, trailing: '1 h 08', onComplete: noop, onUncomplete: noop },
    globals: { day: 'done' },
    decorators: [
        (Story) => (
            <ul
                style={{
                    listStyle: 'none',
                    margin: 0,
                    padding: 'var(--solo-block-pad)',
                    borderRadius: 'var(--solo-block-radius)',
                    background: 'var(--solo-block)',
                    maxWidth: 760,
                }}
            >
                <Story />
            </ul>
        ),
    ],
} satisfies Meta<typeof TaskRow>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Done: Story = {};

/** An unfinished pick: an open box and no time. */
export const Open: Story = { args: { task: { ...done, done: false, minutes: 0 }, trailing: null } };

/** Completed on its Trello board: the tick cannot be taken back here. */
export const DoneOnTheBoard: Story = { args: { task: { ...done, can_untick: false } } };

export const LongTitle: Story = {
    args: { task: { ...done, name: 'Korekta faktury 14/09: dane odbiorcy, adres korespondencyjny i numer zamówienia' } },
};

/** Desktop: the duration right after the title instead of at the row's end. */
export const Desktop: Story = { globals: { day: 'done', viewport: { value: 'desktop', isRotated: false } } };

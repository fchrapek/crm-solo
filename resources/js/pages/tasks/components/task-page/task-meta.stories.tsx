import type { Meta, StoryObj } from '@storybook/react-vite';
import { mainColumn } from './story-columns';
import { clock, task, trelloTask } from './story-fixtures';
import { TaskMeta } from './task-header';

const meta = {
    title: 'Zadania/Task meta',
    component: TaskMeta,
    decorators: [mainColumn],
    args: { task, today: clock.today, locale: 'pl' },
    render: (args, { globals }) => <TaskMeta {...args} locale={globals.locale} />,
} satisfies Meta<typeof TaskMeta>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Plain: Story = {};

export const Trello: Story = { args: { task: trelloTask } };

export const Overdue: Story = { args: { task: { ...task, due_day: '2026-10-15', due_date: '2026-10-15', is_overdue: true } } };

export const WithParent: Story = { args: { task: { ...task, parent_task: { id: 41, name: 'Sezon jesienny na stronie' } } } };

export const NoProject: Story = { args: { task: { ...task, project: null } } };

export const NoDueDate: Story = { args: { task: { ...task, due_day: null, due_date: null } } };

export const DoneInTheCrm: Story = { args: { task: { ...task, is_done: true, finished_at: '2026-10-23T10:00:00Z', can_unfinish: true } } };

export const CardCompleted: Story = { args: { task: { ...trelloTask, is_done: true, is_completed: true } } };

/** Everything at once: parent, overdue, Trello. */
export const Every: Story = {
    args: {
        task: {
            ...trelloTask,
            due_day: '2026-10-15',
            due_date: '2026-10-15',
            is_overdue: true,
            parent_task: { id: 41, name: 'Sezon jesienny na stronie' },
        },
    },
};

export const Desktop: Story = { ...Every, globals: { viewport: { value: 'desktop', isRotated: false } } };

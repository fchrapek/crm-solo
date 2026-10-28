import type { Meta, StoryObj } from '@storybook/react-vite';
import { clock, task, trelloTask } from './story-fixtures';
import { TaskHeader } from './task-header';

const meta = {
    title: 'Zadania/Zadanie/Header',
    component: TaskHeader,
    args: { task, today: clock.today, locale: 'pl' },
    render: (args, { globals }) => <TaskHeader {...args} locale={globals.locale} />,
} satisfies Meta<typeof TaskHeader>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Plain: Story = {};

export const FromTrello: Story = { args: { task: trelloTask } };

export const Overdue: Story = { args: { task: { ...task, due_day: '2026-10-15', due_date: '2026-10-15', is_overdue: true } } };

export const Done: Story = { args: { task: { ...task, is_done: true, finished_at: '2026-10-23T10:00:00Z', can_unfinish: true } } };

export const Subtask: Story = { args: { task: { ...task, parent_task: { id: 41, name: 'Sezon jesienny na stronie' } } } };

export const TrelloOverdue: Story = { args: { task: { ...trelloTask, due_day: '2026-10-15', due_date: '2026-10-15', is_overdue: true } } };

export const WithoutProject: Story = { args: { task: { ...task, project: null } } };

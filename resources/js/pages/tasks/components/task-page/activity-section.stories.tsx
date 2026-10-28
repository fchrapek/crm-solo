import type { Meta, StoryObj } from '@storybook/react-vite';
import { ActivitySection } from './activity-section';
import { sideColumn } from './story-columns';
import { activity, clock, live, NOW } from './story-fixtures';

const meta = {
    title: 'Zadania/Zadanie/Aktywność',
    component: ActivitySection,
    decorators: [sideColumn],
    args: { taskId: 42, activity, live: null, clock, now: NOW, locale: 'pl' },
    render: (args, { globals }) => <ActivitySection {...args} locale={globals.locale} />,
} satisfies Meta<typeof ActivitySection>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Rows: Story = {};

export const AgentWorking: Story = { args: { live } };

export const WholeHistory: Story = { args: { full: true, activity: { ...activity, more: false } } };

export const Empty: Story = { args: { activity: { rows: [], more: false } } };

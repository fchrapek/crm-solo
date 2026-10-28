import type { Meta, StoryObj } from '@storybook/react-vite';
import { mainColumn } from './story-columns';
import { BriefSection } from './brief-section';
import { brief, clock, emptyBrief, NOW } from './story-fixtures';

const meta = {
    title: 'Zadania/Zadanie/Brief',
    component: BriefSection,
    decorators: [mainColumn],
    args: { taskId: 42, brief, now: NOW, today: clock.today, locale: 'pl' },
    render: (args, { globals }) => <BriefSection {...args} locale={globals.locale} />,
} satisfies Meta<typeof BriefSection>;

export default meta;

type Story = StoryObj<typeof meta>;

export const ChangedByAgent: Story = {};

export const Empty: Story = { args: { brief: emptyBrief } };

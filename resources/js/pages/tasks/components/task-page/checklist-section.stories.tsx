import type { Meta, StoryObj } from '@storybook/react-vite';
import { mainColumn } from './story-columns';
import { ChecklistSection } from './checklist-section';
import { card } from './story-fixtures';

const meta = {
    title: 'Zadania/Zadanie/Checklista',
    component: ChecklistSection,
    decorators: [mainColumn],
    args: { card, trelloUrl: 'https://trello.com/c/demo' },
} satisfies Meta<typeof ChecklistSection>;

export default meta;

type Story = StoryObj<typeof meta>;

export const FromTrello: Story = {};

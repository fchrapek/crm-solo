import type { Meta, StoryObj } from '@storybook/react-vite';
import { DescriptionSection } from './description-section';
import { mainColumn } from './story-columns';
import { task, trelloTask } from './story-fixtures';

const meta = {
    title: 'Zadania/Zadanie/Opis',
    component: DescriptionSection,
    decorators: [mainColumn],
    args: { task },
} satisfies Meta<typeof DescriptionSection>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Written: Story = {};

export const FromTrello: Story = { args: { task: trelloTask } };

export const Empty: Story = { args: { task: { ...task, description: null, description_markdown: '' } } };

/** Below 768 px a long description is clamped to four lines behind Więcej; the page shows it whole from 768 px. */
export const LongOnPhone: Story = {
    args: {
        task: {
            ...task,
            description_markdown: `${task.description_markdown}\n- Po eksporcie podmień plik na stronie i usuń stary z mediów\n\nObecny cennik: [meble-przykladowe.test/cennik](https://meble-przykladowe.test/cennik)`,
        },
    },
};

/** A card with no description keeps Edytuj w Trello as its only action. */
export const EmptyFromTrello: Story = { args: { task: { ...trelloTask, description: null, description_markdown: '' } } };

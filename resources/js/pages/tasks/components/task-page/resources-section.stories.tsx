import type { Meta, StoryObj } from '@storybook/react-vite';
import { mainColumn } from './story-columns';
import { ResourcesSection } from './resources-section';
import { resources } from './story-fixtures';

const meta = {
    title: 'Zadania/Zadanie/Zasoby',
    component: ResourcesSection,
    decorators: [mainColumn],
    args: { taskId: 42, resources },
} satisfies Meta<typeof ResourcesSection>;

export default meta;

type Story = StoryObj<typeof meta>;

export const FilesAndLinks: Story = {};

export const Empty: Story = { args: { resources: { files: [], images: [], links: [] } } };

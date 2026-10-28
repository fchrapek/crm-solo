import type { Meta, StoryObj } from '@storybook/react-vite';
import { ActorMark } from './actor-mark';

const meta = {
    title: 'Solo/Actor mark',
    component: ActorMark,
    args: { via: null, name: 'Filip Chrapek' },
} satisfies Meta<typeof ActorMark>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Person: Story = {};

export const Agent: Story = { args: { via: 'mcp', agent: 'Claude' } };

export const Codex: Story = { args: { via: 'cli', agent: 'Codex', mark: 'CX' } };

import type { Meta, StoryObj } from '@storybook/react-vite';
import { Chip, StatusDot } from './chip';

const meta = {
    title: 'Redesign/Chip',
    component: Chip,
    argTypes: { status: { control: 'select', options: ['waiting', 'done', 'open', 'agent'] } },
    args: { status: 'waiting', children: 'czeka na wysyłkę' },
} satisfies Meta<typeof Chip>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Waiting: Story = {};

export const Done: Story = { args: { status: 'done', children: 'wysłany' } };

export const Open: Story = { args: { status: 'open', children: 'szkic' } };

/** An agent at work is neutral with the ink dot: cobalt stays with the selection. */
export const AgentWorking: Story = { args: { status: 'agent', children: 'agent pracuje' } };

export const Dots: Story = {
    render: () => (
        <div style={{ display: 'flex', gap: 'var(--role-space-12)' }}>
            <StatusDot status="waiting" />
            <StatusDot status="done" />
            <StatusDot status="open" />
            <StatusDot status="agent" />
        </div>
    ),
};

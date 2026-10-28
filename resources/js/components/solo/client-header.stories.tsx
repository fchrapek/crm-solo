import type { Meta, StoryObj } from '@storybook/react-vite';
import { AgentChip, PhoneNavBar } from './client-header';

const meta = {
    title: 'Solo/Agent chip',
    component: AgentChip,
    args: { children: 'claude · 14 min' },
} satisfies Meta<typeof AgentChip>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Chip: Story = {};

export const LongAgentName: Story = { args: { children: 'codex · 1 h 05' } };

/** Where it lives: the phone nav bar, between the back link and the ••• menu. */
export const InNavBar: Story = {
    render: (args) => (
        <PhoneNavBar back={{ href: '#', label: 'Pracownia Mebli' }}>
            <AgentChip {...args} />
        </PhoneNavBar>
    ),
};

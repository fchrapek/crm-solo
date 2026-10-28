import type { Meta, StoryObj } from '@storybook/react-vite';
import { StagePill } from './stage-pill';

const STAGES = ['active', 'paused', 'churned', 'prospect', 'offer_sent'] as const;

const meta = {
    title: 'Solo/Stage pill',
    component: StagePill,
    argTypes: {
        stage: { control: 'select', options: STAGES },
    },
    args: { stage: 'active' },
} satisfies Meta<typeof StagePill>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Active: Story = {};

export const Paused: Story = { args: { stage: 'paused' } };

export const Churned: Story = { args: { stage: 'churned' } };

export const EveryStage: Story = {
    render: () => (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--solo-space-3)' }}>
            {STAGES.map((stage) => (
                <StagePill key={stage} stage={stage} />
            ))}
        </div>
    ),
};

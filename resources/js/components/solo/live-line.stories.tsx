import type { Meta, StoryObj } from '@storybook/react-vite';
import { LiveLine } from './live-line';

const meta = {
    title: 'Solo/Live line',
    component: LiveLine,
    args: { children: 'Codex pracuje · 12 min' },
} satisfies Meta<typeof LiveLine>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Medium: Story = {};

export const Small: Story = { args: { size: 'sm' } };

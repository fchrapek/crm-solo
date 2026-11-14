import type { Meta, StoryObj } from '@storybook/react-vite';
import { HoursBar } from './hours-bar';

const meta = {
    title: 'Redesign/Hours bar',
    component: HoursBar,
    args: { used: 12, available: 30, size: 'block' },
} satisfies Meta<typeof HoursBar>;

export default meta;

type Story = StoryObj<typeof meta>;

export const InPool: Story = {};

export const Near: Story = { args: { used: 28 } };

/** Past the pool the overrun is the vermilion segment. */
export const Over: Story = { args: { used: 36.8 } };

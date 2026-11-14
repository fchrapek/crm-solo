import type { Meta, StoryObj } from '@storybook/react-vite';
import { HelperWindow } from './windows';

const meta = {
    title: 'Redesign/Helper windows',
    component: HelperWindow,
    args: { kind: 'plan' },
    decorators: [(Story) => <div style={{ display: 'flex', flexDirection: 'column', width: 348 }}>{Story()}</div>],
} satisfies Meta<typeof HelperWindow>;

export default meta;

type Story = StoryObj<typeof meta>;

/** Example content only: made-up tasks, and Dodaj does nothing. */
export const PlanTheDay: Story = {};

export const PrepareAReport: Story = { args: { kind: 'report' } };

/** On a client record the links are the client's own tabs. */
export const WhatIsGoingOn: Story = { args: { kind: 'client', links: [{ label: 'Zadania', href: '#' }, { label: 'Rozliczenia', href: '#' }, { label: 'Dziennik', href: '#' }] } };

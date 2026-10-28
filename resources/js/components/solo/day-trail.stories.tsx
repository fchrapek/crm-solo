import type { Meta, StoryObj } from '@storybook/react-vite';
import { DayTrail } from './day-trail';

const DESKTOP = { viewport: { value: 'desktop', isRotated: false } };

const meta = {
    title: 'Dziś/Day trail',
    component: DayTrail,
    args: { current: 'today' },
    parameters: {
        docs: { description: { component: 'One set of links: the Width=Full segmented control on phones, the caps text trail from 768 px.' } },
    },
} satisfies Meta<typeof DayTrail>;

export default meta;

type Story = StoryObj<typeof meta>;

export const PhoneToday: Story = {};

export const PhoneSummary: Story = { args: { current: 'done' } };

export const PhoneTomorrow: Story = { args: { current: 'tomorrow', closed: true } };

export const PhoneDayClosed: Story = { args: { current: 'done', closed: true }, globals: { day: 'done' } };

export const PhoneOnTimer: Story = { globals: { day: 'timer' } };

export const DesktopToday: Story = { globals: DESKTOP };

export const DesktopSummary: Story = { args: { current: 'done' }, globals: DESKTOP };

export const DesktopTomorrow: Story = { args: { current: 'tomorrow', closed: true }, globals: DESKTOP };

export const DesktopDayClosed: Story = { args: { current: 'done', closed: true }, globals: { ...DESKTOP, day: 'done' } };

/** Off the three stages nothing is current. */
export const NoCurrentStep: Story = { args: { current: undefined } };

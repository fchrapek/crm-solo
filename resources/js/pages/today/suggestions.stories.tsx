import type { Meta, StoryObj } from '@storybook/react-vite';
import { Suggestions } from './plan';
import { suggestions } from './story-fixtures';

/** Figma: Plan jutro, Propozycje: List rows with + Jutro, then the way to the full list. */
const meta = {
    title: 'Dziś/Propozycje',
    component: Suggestions,
    args: { date: '2026-10-24', suggestions: { tasks: suggestions, leftover: 1, open: 23 } },
    decorators: [
        (Story) => (
            <div style={{ maxWidth: 380 }}>
                <Story />
            </div>
        ),
    ],
} satisfies Meta<typeof Suggestions>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Phone: Story = {};

export const Desktop: Story = { globals: { viewport: { value: 'desktop', isRotated: false } } };

export const OneLeft: Story = { args: { suggestions: { tasks: suggestions.slice(0, 1), leftover: 0, open: 4 } } };

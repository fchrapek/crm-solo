import type { Meta, StoryObj } from '@storybook/react-vite';
import { FreeSlots } from './plan';

/** Figma: A2f / Free slots on Plan jutro. */
const meta = {
    title: 'Dziś/Free slots',
    component: FreeSlots,
    args: { free: 2, proposing: true },
    decorators: [
        (Story) => (
            <div style={{ maxWidth: 760 }}>
                <Story />
            </div>
        ),
    ],
} satisfies Meta<typeof FreeSlots>;

export default meta;

type Story = StoryObj<typeof meta>;

/** Phones: Propozycje sit below. */
export const BesideSuggestions: Story = {};

/** From 768 px: Propozycje sit beside. */
export const BesideSuggestionsDesktop: Story = { globals: { viewport: { value: 'desktop', isRotated: false } } };

/** Nothing to suggest: the slots lead to the full task list. */
export const ToTheTaskList: Story = { args: { proposing: false } };

export const OneSlot: Story = { args: { free: 1, proposing: false } };

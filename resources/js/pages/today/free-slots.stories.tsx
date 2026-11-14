import type { Meta, StoryObj } from '@storybook/react-vite';
import { Tile } from '@/components/solo/tile';
import { FreeSlots } from './plan';

/** Figma: Jutro, the free places as the list's last row. */
const meta = {
    title: 'Dziś/Free slots',
    component: FreeSlots,
    args: { free: 2, proposing: true },
    decorators: [
        (Story) => (
            <Tile kind="list" style={{ maxWidth: 640 }}>
                <ul style={{ margin: 0, padding: 0 }}>
                    <Story />
                </ul>
            </Tile>
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

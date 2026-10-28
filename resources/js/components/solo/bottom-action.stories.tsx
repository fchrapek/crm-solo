import { Icon } from '@/components/ui/icon';
import type { Meta, StoryObj } from '@storybook/react-vite';
import { BottomAction } from './bottom-action';
import { SoloButton } from './primitives';

const meta = {
    title: 'Solo/Bottom action',
    component: BottomAction,
    args: {
        children: (
            <SoloButton variant="ink" size="md">
                Zapisz plan →
            </SoloButton>
        ),
    },
    parameters: {
        docs: {
            description: {
                component: 'Phones: fixed at the bottom of the frame, above where the tab bar sits. From 768 px the buttons render inline.',
            },
        },
    },
} satisfies Meta<typeof BottomAction>;

export default meta;

type Story = StoryObj<typeof meta>;

/** Surface=Paper: Plan jutro's Zapisz plan. */
export const Paper: Story = {};

export const PaperWithSecondary: Story = {
    args: {
        secondary: (
            <SoloButton variant="text" size="sm">
                Wróć do dnia
            </SoloButton>
        ),
    },
};

/** Surface=Timer: Stop on the paper with the vermilion label. */
export const Timer: Story = {
    globals: { day: 'timer' },
    args: {
        children: (
            <SoloButton variant="stop" size="md">
                Stop
            </SoloButton>
        ),
    },
};

/** Surface=Done: Zamknij dzień. */
export const Done: Story = {
    globals: { day: 'done' },
    args: { children: <SoloButton size="md">Zamknij dzień</SoloButton> },
};

/** Surface=Done after the close: Zaplanuj jutro, with Otwórz dzień ponownie above it. */
export const DoneWithSecondary: Story = {
    globals: { day: 'done' },
    args: {
        children: (
            <SoloButton size="md">
                Zaplanuj jutro <Icon name="arrow-right" size={16} />
            </SoloButton>
        ),
        secondary: (
            <SoloButton variant="text" size="sm">
                Otwórz dzień ponownie
            </SoloButton>
        ),
    },
};

/** From 768 px the bar steps aside and both buttons sit where the page puts them. */
export const Desktop: Story = { ...DoneWithSecondary, globals: { day: 'done', viewport: { value: 'desktop', isRotated: false } } };

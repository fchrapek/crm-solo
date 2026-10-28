import { Icon } from '@/components/ui/icon';
import type { Meta, StoryObj } from '@storybook/react-vite';
import { SoloButton } from './primitives';

const VARIANTS = ['primary', 'ink', 'outline', 'pill', 'ghost', 'text', 'stop'] as const;
const SIZES = ['sm', 'md', 'lg', 'touch'] as const;

const meta = {
    title: 'Solo/Button',
    component: SoloButton,
    argTypes: {
        variant: { control: 'select', options: VARIANTS },
        size: { control: 'select', options: SIZES },
    },
    args: { variant: 'primary', size: 'md', children: 'Zapisz' },
} satisfies Meta<typeof SoloButton>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Primary: Story = {};

/** The main action of a screen (Figma Ink md): Start, Zamknij dzień, Zapisz plan. */
export const InkMd: Story = { args: { variant: 'ink', size: 'md', children: 'Zapisz plan →' } };

/** The secondary action, one size wherever it sits: the masthead search, a record header's actions, a dialog's Anuluj. */
export const Pill: Story = { args: { variant: 'pill', children: 'Anuluj' } };

export const SearchPill: Story = { args: { variant: 'pill', children: '⌘K  Szukaj' } };

export const OutlineSm: Story = { args: { variant: 'outline', size: 'sm', children: 'Kopiuj dla agenta' } };

export const TextSm: Story = { args: { variant: 'text', size: 'sm', children: 'Otwórz dzień ponownie' } };

export const Stop: Story = { args: { variant: 'stop', size: 'md', children: 'Stop' } };

export const WithIcon: Story = {
    args: {
        variant: 'ink',
        size: 'md',
        children: (
            <>
                <Icon name="play" size={16} filled />
                Start
            </>
        ),
    },
};

/** Ink md beside the pill: the pair a dialog footer and a record header put side by side. */
export const MdAndPill: Story = {
    render: () => (
        <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--solo-space-3)' }}>
            <SoloButton variant="pill">Anuluj</SoloButton>
            <SoloButton variant="ink" size="md">
                Zapisz
            </SoloButton>
        </div>
    ),
};

export const EveryVariant: Story = {
    render: (args) => (
        <div style={{ display: 'grid', gap: 'var(--solo-space-4)' }}>
            {SIZES.map((size) => (
                <div key={size} style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--solo-space-3)' }}>
                    {VARIANTS.map((variant) => (
                        <SoloButton key={variant} {...args} variant={variant} size={size}>
                            {variant === 'pill' ? 'pill' : `${variant} ${size}`}
                        </SoloButton>
                    ))}
                </div>
            ))}
        </div>
    ),
};

/** Timer and Done surfaces recolour every variant; Stop and Ink take the paper there. */
export const OnTimer: Story = { ...EveryVariant, globals: { day: 'timer' } };

export const OnDone: Story = { ...EveryVariant, globals: { day: 'done' } };

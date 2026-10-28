import type { Meta, StoryObj } from '@storybook/react-vite';
import { Icon, ICON_NAMES, type IconSize } from './icon';

const SIZES: IconSize[] = [16, 20, 24];
const FILLED = ['play', 'stop', 'done', 'pin', 'calendar', 'client', 'contacts', 'settings'] as const;

const meta = {
    title: 'Solo/Icon',
    component: Icon,
    args: { name: 'check', size: 20 },
    argTypes: { name: { control: 'select', options: ICON_NAMES }, size: { control: 'select', options: SIZES } },
} satisfies Meta<typeof Icon>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Single: Story = {};

/** Paper draws Fluent, Boring SaaS Lucide: switch the theme in the toolbar. */
export const Every: Story = {
    render: () => (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(112px, 1fr))', gap: 'var(--solo-space-3)' }}>
            {ICON_NAMES.map((name) => (
                <div key={name} style={{ display: 'grid', gap: 'var(--solo-space-1)', justifyItems: 'start', fontSize: 12 }}>
                    <span style={{ display: 'flex', gap: 'var(--solo-space-2)', alignItems: 'center' }}>
                        {SIZES.map((size) => (
                            <Icon key={size} name={name} size={size} />
                        ))}
                    </span>
                    <span>{name}</span>
                </div>
            ))}
        </div>
    ),
};

/** Filled only for the active tab-bar tab, the day markers and the timer's play and stop. */
export const Filled: Story = {
    render: () => (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--solo-space-4)' }}>
            {FILLED.map((name) => (
                <span key={name} style={{ display: 'flex', gap: 'var(--solo-space-2)', alignItems: 'center' }}>
                    <Icon name={name} size={24} />
                    <Icon name={name} size={24} filled />
                </span>
            ))}
        </div>
    ),
};

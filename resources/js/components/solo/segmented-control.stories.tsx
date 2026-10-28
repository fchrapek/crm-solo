import { Icon } from '@/components/ui/icon';
import type { Meta, StoryObj } from '@storybook/react-vite';
import { useState } from 'react';
import { SegmentedControl } from './segmented-control';

type Look = 'light' | 'dark' | 'system';

const LOOKS = [
    { value: 'light', label: 'Jasny' },
    { value: 'dark', label: 'Ciemny' },
    { value: 'system', label: 'System' },
] as const;

function Hug({ initial = 'light' }: { initial?: Look }) {
    const [value, setValue] = useState<Look>(initial);

    return <SegmentedControl label="Wygląd" value={value} onChange={setValue} options={LOOKS} />;
}

const DAY = (closed: boolean) =>
    [
        { value: 'today', label: <span>Dziś</span>, href: '#dzis' },
        {
            value: 'done',
            hug: true,
            href: '#gotowe',
            label: closed ? (
                <>
                    <Icon name="done" size={16} filled aria-label="Dzień zamknięty" />
                    <span>Podsumowanie</span>
                </>
            ) : (
                <span>Podsumowanie</span>
            ),
        },
        { value: 'tomorrow', label: <span>Jutro</span>, href: '#jutro' },
    ] as const;

const meta = {
    title: 'Solo/Segmented control',
    component: Hug,
} satisfies Meta<typeof Hug>;

export default meta;

type Story = StoryObj<typeof meta>;

/** Width=Hug: the settings toggles (Wygląd). */
export const WidthHug: Story = {};

/** Width=Full: the phone day trail, links across the column, Podsumowanie hugging its label. */
export const WidthFull: Story = {
    render: () => <SegmentedControl label="Dzień" width="full" current="step" value="today" options={DAY(false)} />,
};

export const WidthFullDayClosed: Story = {
    render: () => <SegmentedControl label="Dzień" width="full" current="step" value="done" options={DAY(true)} />,
};

export const WidthFullOnTimer: Story = { ...WidthFull, globals: { day: 'timer' } };

export const WidthFullOnDone: Story = { ...WidthFullDayClosed, globals: { day: 'done' } };

export const Every: Story = {
    render: () => (
        <div style={{ display: 'grid', gap: 'var(--solo-space-4)', maxWidth: 358 }}>
            <Hug />
            <SegmentedControl label="Dzień" width="full" current="step" value="today" options={DAY(false)} />
            <SegmentedControl label="Dzień" width="full" current="step" value="done" options={DAY(true)} />
            <SegmentedControl label="Dzień" width="full" current="step" value="tomorrow" options={DAY(true)} />
        </div>
    ),
};

import type { Meta, StoryObj } from '@storybook/react-vite';
import { SectionAction, SectionHead } from './primitives';

const meta = {
    title: 'Solo/Section head',
    component: SectionHead,
    args: { label: 'Dziś', meta: '2 z 5 wybrane' },
    decorators: [
        (Story) => (
            <div style={{ maxWidth: 380 }}>
                <Story />
            </div>
        ),
    ],
} satisfies Meta<typeof SectionHead>;

export default meta;

type Story = StoryObj<typeof meta>;

export const WithMeta: Story = {};

export const WithLink: Story = { args: { meta: undefined, action: <SectionAction>Nowe zadanie</SectionAction> } };

export const Quiet: Story = { args: { label: 'Poza planem', meta: '1', quiet: true } };

/** Figma Inset=None: the head on the page with no block under it (Jutro on Podsumowanie, the month card). */
export const InsetNone: Story = { args: { label: 'Jutro', meta: 'sobota', inset: 'none' } };

export const Body: Story = { args: { kind: 'body', label: 'Czas', meta: '2 h 52 min łącznie' } };

export const Every: Story = {
    render: () => (
        <div style={{ display: 'grid', gap: 'var(--solo-space-5)' }}>
            <SectionHead label="Dziś" meta="2 z 5 wybrane" />
            <SectionHead label="Propozycje" action={<SectionAction>Nowe zadanie</SectionAction>} />
            <SectionHead label="Poza planem" meta="1" quiet />
            <SectionHead label="Jutro" meta="sobota" inset="none" />
            <SectionHead kind="body" label="Czas" meta="2 h 52 min łącznie" />
            <SectionHead kind="body" label="Aktywność" />
        </div>
    ),
};

export const OnDone: Story = { ...Every, globals: { day: 'done' } };

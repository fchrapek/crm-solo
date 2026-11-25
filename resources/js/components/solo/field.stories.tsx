import type { Meta, StoryObj } from '@storybook/react-vite';
import { fieldControl, SoloField, SoloFieldRow, SoloInput, SoloTextarea } from './field';
import { Tile } from './tile';

/** Fields sit on a tile or a dialog: their fill is the page tone, so on the bare page they would vanish. */
const meta = {
    title: 'Solo/Field',
    component: SoloField,
    args: { label: 'Tytuł', htmlFor: 'title', children: null },
    decorators: [
        (Story) => (
            <Tile kind="plain" style={{ maxWidth: 520 }}>
                <Story />
            </Tile>
        ),
    ],
    render: (args) => (
        <SoloField {...args}>
            <SoloInput {...fieldControl(args.htmlFor, args.error)} placeholder="Krótka etykieta widoczna na liście" />
        </SoloField>
    ),
} satisfies Meta<typeof SoloField>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Input: Story = {};

export const WithError: Story = { args: { error: 'Podaj tytuł.' } };

/** Figma B, Filled on tone: empty, filled, error and disabled; hover and focus show on the pointer and the keyboard. */
export const States: Story = {
    render: () => (
        <div style={{ display: 'grid', gap: 'var(--solo-space-4)' }}>
            <SoloField label="Pusty" htmlFor="s-empty">
                <SoloInput id="s-empty" placeholder="Np. Aktualizacja cennika" />
            </SoloField>
            <SoloField label="Wypełniony" htmlFor="s-filled">
                <SoloInput id="s-filled" defaultValue="Aktualizacja cennika PDF" />
            </SoloField>
            <SoloField label="Błąd" htmlFor="s-error" error="Wpisz nazwę zadania.">
                <SoloInput {...fieldControl('s-error', 'Wpisz nazwę zadania.')} defaultValue="Aktualizacja cennika PDF" />
            </SoloField>
            <SoloField label="Wyłączony" htmlFor="s-disabled">
                <SoloInput id="s-disabled" defaultValue="Aktualizacja cennika PDF" disabled />
            </SoloField>
        </div>
    ),
};

export const Form: Story = {
    render: () => (
        <div style={{ display: 'grid', gap: 'var(--solo-space-4)' }}>
            <SoloField label="Tytuł" htmlFor="f-title">
                <SoloInput id="f-title" placeholder="Krótka etykieta widoczna na liście" />
            </SoloField>
            <SoloField label="Opis" htmlFor="f-desc">
                <SoloTextarea id="f-desc" rows={4} placeholder="Szczegóły widoczne po otwarciu wpisu" />
            </SoloField>
            <SoloFieldRow>
                <SoloField label="Początek" htmlFor="f-start">
                    <SoloInput id="f-start" type="datetime-local" defaultValue="2026-10-23T13:20" />
                </SoloField>
                <SoloField label="Koniec" htmlFor="f-end">
                    <SoloInput id="f-end" type="datetime-local" defaultValue="2026-10-23T14:20" />
                </SoloField>
            </SoloFieldRow>
        </div>
    ),
};

import type { Meta, StoryObj } from '@storybook/react-vite';
import { fieldControl, SoloField, SoloFieldRow, SoloInput, SoloTextarea } from './field';

const meta = {
    title: 'Solo/Field',
    component: SoloField,
    args: { label: 'Tytuł', htmlFor: 'title', children: null },
    render: (args) => (
        <div style={{ maxWidth: 472 }}>
            <SoloField {...args}>
                <SoloInput {...fieldControl(args.htmlFor, args.error)} placeholder="Krótka etykieta widoczna na liście" />
            </SoloField>
        </div>
    ),
} satisfies Meta<typeof SoloField>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Input: Story = {};

export const WithError: Story = { args: { error: 'Podaj tytuł.' } };

export const Form: Story = {
    render: () => (
        <div style={{ display: 'grid', gap: 'var(--solo-space-4)', maxWidth: 472 }}>
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

import type { Meta, StoryObj } from '@storybook/react-vite';
import { Chip } from './chip';
import { HeaderAction } from './client-header';
import { RecordFrame } from './record-frame';
import { Pills } from './shell/pills';

const meta = {
    title: 'Redesign/RecordFrame',
    component: RecordFrame,
    args: { title: 'Pracownia Mebli' },
} satisfies Meta<typeof RecordFrame>;

export default meta;

type Story = StoryObj<typeof meta>;

/** The actions stand at the title's end from 768 px; a phone shows the main button and "more". */
export const WithActions: Story = {
    args: {
        chip: <Chip status="agent">claude pracuje · 14 min</Chip>,
        actions: (
            <>
                <HeaderAction>Kopiuj polecenie dla agenta</HeaderAction>
                <HeaderAction primary>Nowe zadanie</HeaderAction>
            </>
        ),
        main: <HeaderAction primary>Nowe zadanie</HeaderAction>,
    },
};

export const Archived: Story = { args: { note: 'Klient zarchiwizowany' } };

/** Rozliczenia: the month as the title, how it stands as a line under it, and the months as pills beside it (under it on a phone). */
export const WithAside: Story = {
    args: {
        title: 'Wrzesień',
        note: 'Skończył się 30.09 · otwarty · raport do wygenerowania',
        noteKind: 'line',
        aside: (
            <Pills
                label="Miesiące"
                items={[
                    { label: 'Październik', href: '#', note: 'w toku' },
                    { label: 'Wrzesień', href: '#', active: true },
                    { label: 'Sierpień', href: '#' },
                    { label: 'Lipiec', href: '#' },
                ]}
            />
        ),
    },
};

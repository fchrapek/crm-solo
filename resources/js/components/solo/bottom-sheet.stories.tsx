import type { Meta, StoryObj } from '@storybook/react-vite';
import { BottomSheet } from './bottom-sheet';
import { fieldControl, SoloField, SoloInput } from './field';

const meta = {
    title: 'Solo/Bottom sheet',
    component: BottomSheet,
    args: {
        defaultOpen: true,
        title: 'Nowe zadanie',
        action: 'save',
        onSubmit: (event) => event.preventDefault(),
        children: (
            <SoloField label="Nazwa" htmlFor="sheet-name">
                <SoloInput {...fieldControl('sheet-name')} defaultValue="Aktualizacja cennika PDF" />
            </SoloField>
        ),
    },
} satisfies Meta<typeof BottomSheet>;

export default meta;

type Story = StoryObj<typeof meta>;

/** Header=Cancel · Save: Anuluj, the centred title and Zapisz (form sheets). */
export const SaveHeader: Story = {};

export const SaveHeaderWithSubtitle: Story = { args: { title: 'Zaloguj czas', description: 'Aktualizacja cennika PDF · Pracownia Mebli' } };

export const SaveHeaderSaving: Story = { args: { saving: true } };

/** Header=Close: the title and the round close button. */
export const CloseHeader: Story = {
    args: { action: 'close', title: 'Agenci', onSubmit: undefined, children: <p style={{ margin: 0 }}>Repozytoria klienta Pracownia Mebli.</p> },
};

/** The picker sheet: Wyczyść, the centred title and Gotowe. */
export const DoneHeader: Story = {
    args: { action: 'done', title: 'Projekt', onClear: () => {}, onSubmit: undefined, children: <p style={{ margin: 0 }}>Strona WWW</p> },
};

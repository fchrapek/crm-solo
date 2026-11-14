import type { Meta, StoryObj } from '@storybook/react-vite';
import { ActionMenu, ActionMenuItem } from './action-menu';
import { Chip } from './chip';
import { IconButton } from './icon-button';
import { SoloButton } from './primitives';
import { Row } from './row';
import { Tile, TileRows } from './tile';

const meta = {
    title: 'Redesign/Row',
    component: Row,
    args: { title: 'Aktualizacja cennika PDF', as: 'div', rule: false },
    decorators: [
        (Story) => (
            <Tile>
                <Story />
            </Tile>
        ),
    ],
} satisfies Meta<typeof Row>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Meta_: Story = { name: 'Meta', args: { meta: 'Pracownia Mebli' } };

export const Action: Story = {
    args: {
        title: 'Wybierz kolejne',
        meta: '3 wolne miejsca',
        action: (
            <SoloButton size="control" variant="secondary">
                Dodaj
            </SoloButton>
        ),
    },
};

const noop = () => {};

export const Checkbox: Story = { args: { check: { done: false, label: 'Oznacz jako zrobione', onToggle: noop }, wideMeta: 'Pracownia Mebli' } };

export const CheckboxDone: Story = { args: { check: { done: true, label: 'Cofnij', onToggle: noop }, time: '48 min' } };

/** Today's pick: a tick and one button; the running one shows its time in vermilion and Stop. */
export const CheckboxAction: Story = {
    args: {
        title: 'Korekta faktury 14/09: dane odbiorcy',
        check: { done: false, label: 'Oznacz jako zrobione', onToggle: noop },
        time: '1:12',
        timeTone: 'over',
        wideMeta: 'Fundacja Testowa',
        action: (
            <SoloButton size="control" variant="stop">
                Stop
            </SoloButton>
        ),
    },
};

export const WithChip: Story = { args: { title: 'Raport za wrzesień', chip: <Chip status="waiting">czeka na wysyłkę</Chip> } };

/** The keyboard or current row, in search results and menus. */
export const Selected: Story = { args: { selected: true, meta: 'klient', title: 'Pracownia Mebli' } };

/** Secondary actions sit behind the "more" button. */
export const WithMore: Story = {
    args: {
        title: 'Dopracowanie podstron usług',
        wideMeta: 'Przychodnia Testowa · z poniedziałku',
        action: (
            <SoloButton size="control" variant="secondary">
                Na dziś
            </SoloButton>
        ),
        more: (
            <ActionMenu trigger={<IconButton icon="more" look="quiet" label="Więcej akcji" />}>
                <ActionMenuItem>Usuń</ActionMenuItem>
            </ActionMenu>
        ),
    },
};

/** Two buttons need `stack`: on a phone the chip and the buttons stand under the title, from 768 px they share its line. */
export const TwoActions: Story = {
    args: {
        title: 'Galeria realizacji',
        time: '480,00 zł',
        stack: true,
        action: (
            <>
                <SoloButton size="control" variant="secondary">
                    Kopiuj fakturę
                </SoloButton>
                <SoloButton size="control" variant="quiet">
                    Wybierz fakturę
                </SoloButton>
            </>
        ),
    },
};

/** Rows in a list are ruled off from each other; a long title wraps on a phone and is cut from 768 px. */
export const InAList: Story = {
    decorators: [],
    render: () => (
        <TileRows>
            <Row rule={false} title="Aktualizacja cennika PDF i podmiana plików w bibliotece mediów sklepu" meta="15.10" />
            <Row title="Techniczne błędy SEO do naprawy" meta="20.10" />
            <Row title="Korekta faktury 14/09: dane odbiorcy" meta="28.10" />
        </TileRows>
    ),
};

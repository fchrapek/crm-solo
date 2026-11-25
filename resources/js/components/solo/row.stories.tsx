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

const facts = [
    { label: 'Klient', value: 'Fundacja Testowa' },
    { label: 'Projekt', value: 'Nowa strona fundacji' },
    { label: 'Termin', value: '2.11', short: true },
    { label: 'Czas', value: '1 h 20 min', short: true },
];

/** Figma: Shared · Row details. One line at rest with no hover band; the chevron before the action opens the pairs under the row in fixed columns; a linked title takes the secondary ink on hover. */
export const Details: Story = {
    args: {
        title: 'Makiety: strona główna i podstrona projektu',
        href: '#',
        details: facts,
        detailsOpen: true,
        action: (
            <SoloButton size="control" variant="quiet">
                Na jutro
            </SoloButton>
        ),
    },
};

/** A running timer's dot and live time stand before the chevron at all times; every row of the list keeps the same columns, a missing value leaves its column empty and a long one is cut with its whole text as the tooltip. */
export const DetailsInAList: Story = {
    decorators: [],
    render: () => (
        <Tile kind="list">
            <TileRows>
                <Row
                    rule={false}
                    title="Optymalizacja zdjęć w galerii"
                    href="#"
                    check={{ done: false, label: 'Oznacz jako zrobione', onToggle: noop }}
                    running={new Date(Date.now() - 24 * 60_000).toISOString()}
                    details={[{ ...facts[0], value: 'PRZEDSIĘBIORSTWO HANDLOWO-USŁUGOWE PRZYKŁADOWE S.C. ANNA TESTOWA-NOWAK, JAN, PIOTR TESTOWY' }, ...facts.slice(1)]}
                    detailsOpen
                    action={<IconButton icon="stop" look="stop" filled label="Stop" />}
                />
                <Row
                    title="Bieżące poprawki na stronie"
                    href="#"
                    check={{ done: false, label: 'Oznacz jako zrobione', onToggle: noop }}
                    time="45 min"
                    timeTone="quiet"
                    details={[facts[0], { label: 'Projekt', value: null }, ...facts.slice(2)]}
                    detailsOpen
                    action={<IconButton icon="play" look="outline" filled label="Start" />}
                />
            </TileRows>
        </Tile>
    ),
};

/** Journal entries: title, chevron and menu at rest; the date leads the details, a long note's text takes the whole line. */
export const DetailsJournal: Story = {
    decorators: [],
    render: () => (
        <Tile kind="list">
            <TileRows>
                {[
                    { title: 'Telefon: migracja hostingu przesunięta na 3.11', date: '15.10', text: 'Telefon: migracja hostingu przesunięta na 3.11\nKlient prosi o przeniesienie poczty w tym samym oknie.' },
                    { title: 'Wizja: do umówienia → umówiona', date: '14.10\u00a0·\u00a009:30', text: null },
                ].map((entry, index) => (
                    <Row
                        key={entry.title}
                        rule={index > 0}
                        title={entry.title}
                        details={[
                            { label: 'Data', value: entry.date },
                            { label: 'Autor', value: 'Filip' },
                            { label: 'Rodzaj', value: entry.text ? 'Notatka' : 'Pole' },
                            { label: 'Tekst', value: entry.text, wide: true },
                        ]}
                        detailsOpen={index === 0}
                        more={<IconButton icon="more" look="quiet" label="Więcej akcji" />}
                    />
                ))}
            </TileRows>
        </Tile>
    ),
};

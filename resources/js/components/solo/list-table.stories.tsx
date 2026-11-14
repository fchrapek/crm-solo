import type { Meta, StoryObj } from '@storybook/react-vite';
import { Chip } from './chip';
import { HoursBar } from './hours-bar';
import { ListTable, ListTableCell, ListTableHead, ListTableRow } from './list-table';
import { Tile } from './tile';

const meta = {
    title: 'Redesign/ListTable',
    component: ListTable,
    args: { label: 'Klienci' },
} satisfies Meta<typeof ListTable>;

export default meta;

type Story = StoryObj<typeof meta>;

/** Captions, a title cell, right-aligned figures and a figure past its limit. A phone keeps the title and the cells not marked wide. */
export const Clients: Story = {
    render: (args) => (
        <Tile kind="list" as="div">
            <div style={{ paddingInline: 12 }}>
                <ListTable {...args}>
                    <thead>
                        <tr>
                            <ListTableHead>Klient</ListTableHead>
                            <ListTableHead>Październik</ListTableHead>
                            <ListTableHead wide>Otwarty</ListTableHead>
                            <ListTableHead align="end">Bilans</ListTableHead>
                            <ListTableHead wide>Uwaga</ListTableHead>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <ListTableCell kind="title">Pracownia Mebli</ListTableCell>
                            <ListTableCell kind="number">
                                <span style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                                    <span style={{ width: 96 }}>
                                        <HoursBar size="tile" used={36.8} available={24} />
                                    </span>
                                    36,8 z 24 h
                                </span>
                            </ListTableCell>
                            <ListTableCell kind="meta" wide>
                                wrzesień
                            </ListTableCell>
                            <ListTableCell kind="number" align="end" tone="over">
                                −12,8 h
                            </ListTableCell>
                            <ListTableCell wide>
                                <Chip status="agent">Claude pracuje</Chip>
                            </ListTableCell>
                        </tr>
                        <tr>
                            <ListTableCell kind="title">Jan Przykładowy</ListTableCell>
                            <ListTableCell kind="number" tone="muted">
                                0,0 h
                            </ListTableCell>
                            <ListTableCell kind="meta" wide />
                            <ListTableCell kind="number" align="end" tone="muted">
                                ·
                            </ListTableCell>
                            <ListTableCell wide />
                        </tr>
                    </tbody>
                </ListTable>
            </div>
        </Tile>
    ),
};

/** Figma: LedgerRow. The row the page is on stands on the ground colour; a minor row is a line between months. A phone swaps the wide figures for one narrow cell. */
export const Ledger: Story = {
    args: { label: 'Bank godzin' },
    render: (args) => (
        <Tile kind="list" as="div">
            <div style={{ paddingInline: 12 }}>
                <ListTable {...args}>
                    <thead>
                        <tr>
                            <ListTableHead>Miesiąc</ListTableHead>
                            <ListTableHead align="end" wide>
                                Rzeczywisty
                            </ListTableHead>
                            <ListTableHead align="end" wide>
                                Rozliczany
                            </ListTableHead>
                            <ListTableHead align="end" narrow>
                                Rzecz. · rozl.
                            </ListTableHead>
                            <ListTableHead align="end">Bilans</ListTableHead>
                            <ListTableHead wide>Stan</ListTableHead>
                        </tr>
                    </thead>
                    <tbody>
                        <ListTableRow>
                            <ListTableCell kind="title">Październik</ListTableCell>
                            <ListTableCell kind="number" align="end" wide>
                                21,4 h
                            </ListTableCell>
                            <ListTableCell kind="number" align="end" wide>
                                21,4 h
                            </ListTableCell>
                            <ListTableCell kind="number" align="end" narrow tone="muted">
                                21,4 · 21,4 h
                            </ListTableCell>
                            <ListTableCell kind="number" align="end" tone="over">
                                −10,2 h
                            </ListTableCell>
                            <ListTableCell wide>w toku</ListTableCell>
                        </ListTableRow>
                        <ListTableRow current>
                            <ListTableCell kind="title">Wrzesień</ListTableCell>
                            <ListTableCell kind="number" align="end" wide>
                                36,8 h
                            </ListTableCell>
                            <ListTableCell kind="number" align="end" wide>
                                36,8 h
                            </ListTableCell>
                            <ListTableCell kind="number" align="end" narrow tone="muted">
                                36,8 · 36,8 h
                            </ListTableCell>
                            <ListTableCell kind="number" align="end" tone="over">
                                −12,8 h
                            </ListTableCell>
                            <ListTableCell wide>otwarty · raport do wygenerowania</ListTableCell>
                        </ListTableRow>
                        <ListTableRow minor>
                            <ListTableCell kind="title">Korekta 01.09</ListTableCell>
                            <ListTableCell wide />
                            <ListTableCell wide />
                            <ListTableCell narrow />
                            <ListTableCell kind="number" align="end">
                                0,0 h
                            </ListTableCell>
                            <ListTableCell wide>bilans otwarcia ustawiony ręcznie</ListTableCell>
                        </ListTableRow>
                    </tbody>
                </ListTable>
            </div>
        </Tile>
    ),
};

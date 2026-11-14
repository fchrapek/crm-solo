import type { Meta, StoryObj } from '@storybook/react-vite';
import { KvRow } from './kv-row';
import { Tile, TileHeader } from './tile';

const meta = {
    title: 'Redesign/KvRow',
    component: KvRow,
    args: { layout: 'tile', label: 'Pakiet', children: '+24,0 h' },
} satisfies Meta<typeof KvRow>;

export default meta;

type Story = StoryObj<typeof meta>;

/** Label and value at the row's ends; figures right aligned, a figure past its limit in vermilion, nothing to say in the secondary tone. */
export const InTile: Story = {
    render: (args) => (
        <Tile kind="plain">
            <TileHeader label="Godziny" />
            <dl style={{ margin: 0, alignSelf: 'stretch' }}>
                <KvRow {...args} />
                <KvRow layout="tile" label="Bilans na październik" tone="over">
                    −12,8 h
                </KvRow>
                <KvRow layout="tile" label="Segment">
                    Mała / średnia firma
                </KvRow>
                <KvRow layout="tile" label="SSH" tone="muted">
                    Nie podano
                </KvRow>
            </dl>
        </Tile>
    ),
};

/** Figma: KeyValue, Total and Total over. The row the rows above add up to. */
export const Total: Story = {
    render: () => (
        <Tile kind="plain">
            <TileHeader label="Miesiąc" />
            <dl style={{ margin: 0, alignSelf: 'stretch' }}>
                <KvRow layout="tile" label="Pakiet">
                    +24,0 h
                </KvRow>
                <KvRow layout="tile" label="Przepracowane · z wpisów">
                    −36,8 h
                </KvRow>
                <KvRow layout="tile" total label="Bilans → otwarcie października" tone="over">
                    −12,8 h
                </KvRow>
            </dl>
        </Tile>
    ),
};

/** Figma: KeyValue of the brief. The caps label with a longer text: above it on a phone, in a column beside it from 768 px. */
export const Field: Story = {
    render: () => (
        <Tile kind="plain">
            <TileHeader label="Brief dla agenta" count="2 pola" />
            <dl style={{ margin: 0, alignSelf: 'stretch' }}>
                <KvRow layout="field" label="Gdzie">
                    Szablon cennik.php w motywie i plik PDF w mediach strony.
                </KvRow>
                <KvRow layout="field" label="Gotowe gdy">
                    Nowy PDF jest podlinkowany na stronie cennika, stary plik usunięty z mediów.
                </KvRow>
                <KvRow layout="field" label="Ograniczenia" tone="muted">
                    nie ustawiono
                </KvRow>
            </dl>
        </Tile>
    ),
};

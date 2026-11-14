import type { Meta, StoryObj } from '@storybook/react-vite';
import { JournalEntry } from './journal-entry';
import { Tile, TileHeader, TileRows } from './tile';

const meta = {
    title: 'Redesign/JournalEntry',
    component: JournalEntry,
    args: { meta: '27.10 · FC', children: 'Marek prosi o wycenę nowej podstrony o kuchniach na wymiar. Odpisać do piątku.' },
} satisfies Meta<typeof JournalEntry>;

export default meta;

type Story = StoryObj<typeof meta>;

/** A plain note, new since the last visit, then an entry of another kind. */
export const InTile: Story = {
    render: (args) => (
        <Tile kind="list">
            <TileHeader label="Relacja z klientem" />
            <TileRows>
                <JournalEntry {...args} isNew rule={false} />
                <JournalEntry meta="02.09 · FC" kind="Rozliczenie">
                    Sierpień zamknięty: bilans −7,6 h przechodzi na wrzesień.
                </JournalEntry>
            </TileRows>
        </Tile>
    ),
};

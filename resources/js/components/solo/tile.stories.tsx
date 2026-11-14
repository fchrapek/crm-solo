import type { Meta, StoryObj } from '@storybook/react-vite';
import { SoloButton, Stat } from './primitives';
import { Row } from './row';
import { GroupHeading, Tile, TileActions, TileHeader, TileInset, TileNote, TileRows } from './tile';

const meta = {
    title: 'Redesign/Tile',
    component: Tile,
    argTypes: { kind: { control: 'select', options: ['list', 'plain', 'timer'] } },
    args: { kind: 'list' },
} satisfies Meta<typeof Tile>;

export default meta;

type Story = StoryObj<typeof meta>;

/** White, for lists: rows bring their own 12 px inset. */
export const List: Story = {
    render: (args) => (
        <Tile {...args}>
            <TileHeader label="Zadania" count={3} />
            <TileRows>
                <Row title="Aktualizacja cennika PDF" meta="Pracownia Mebli" />
                <Row title="Techniczne błędy SEO do naprawy" meta="Pracownia Mebli" />
                <Row title="Korekta faktury 14/09: dane odbiorcy" meta="Fundacja Testowa" />
            </TileRows>
            <TileActions>
                <SoloButton size="control">Dodaj zadanie</SoloButton>
                <SoloButton size="control" variant="secondary">
                    Wszystkie
                </SoloButton>
            </TileActions>
        </Tile>
    ),
};

/** Groups of rows in one tile: the heading outranks the row titles; a past group is quieter, the current one carries the chip, an empty one is one quiet line. */
export const Groups: Story = {
    render: (args) => (
        <Tile {...args}>
            <GroupHeading label="Środa" note="11.11" quiet end="1 z 2 zrobione" />
            <TileRows>
                <Row title="Wybór hostingu i domeny" meta="Fundacja Testowa" />
                <Row title="Makiety: strona główna i podstrona projektu" meta="Fundacja Testowa" />
            </TileRows>
            <GroupHeading label="Czwartek" note="12.11" current="dziś" end="1 z 5 wybrane" />
            <TileRows>
                <Row title="Aktualizacja cennika PDF" meta="Pracownia Mebli" />
            </TileRows>
            <GroupHeading
                label="Piątek"
                note="13.11"
                end={
                    <SoloButton size="control" variant="secondary">
                        Wybierz
                    </SoloButton>
                }
            />
            <TileInset>
                <TileNote>Nic nie zaplanowano</TileNote>
            </TileInset>
        </Tile>
    ),
};

/** One step down, where the rows are smaller too: a menu, a board column. */
export const GroupsCompact: Story = {
    render: (args) => (
        <Tile {...args}>
            <GroupHeading size="compact" label="Rozmowa" note={2} />
            <TileRows>
                <Row title="Marta Lis" meta="Pracownia Wnętrz Testowa" />
                <Row title="Adrian Kruk" meta="Studio Aplikacji Testowe" />
            </TileRows>
        </Tile>
    ),
};

/** A measured figure stands in a plain tile: the poster face sets it apart, not an outline. */
export const Figure: Story = {
    args: { kind: 'plain' },
    render: (args) => (
        <Tile {...args}>
            <Stat kind="tile" label="Październik" value="18 z 22" sub="dni zamkniętych" />
            <SoloButton size="control" variant="secondary">
                Cały miesiąc
            </SoloButton>
        </Tile>
    ),
};

/** The figure past its limit reads in vermilion type. */
export const FigureOver: Story = {
    args: { kind: 'plain' },
    render: (args) => (
        <Tile {...args}>
            <Stat kind="tile" tone="over" label="Godziny" value="36,8 h" sub="pula 30 h" />
        </Tile>
    ),
};

/** Vermilion, for the running timer and nothing else. */
export const Timer: Story = {
    args: { kind: 'timer' },
    render: (args) => (
        <Tile {...args}>
            <Stat kind="tile" tone="on-colour" label="Timer liczy" value="1:12" sub="Korekta faktury 14/09: dane odbiorcy · Fundacja Testowa" />
            <SoloButton size="control" variant="on-colour">
                Stop
            </SoloButton>
        </Tile>
    ),
};

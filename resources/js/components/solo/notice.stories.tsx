import type { Meta, StoryObj } from '@storybook/react-vite';
import { Notice } from './notice';
import { Tile, TileHeader } from './tile';

const meta = {
    title: 'Redesign/Notice',
    component: Notice,
    argTypes: { kind: { control: 'select', options: ['lock', 'warn', 'info'] } },
    args: { kind: 'lock', children: 'Sierpień jest zamknięty od 04.09, więc tych liczb nie zmienisz. Żeby je poprawić, otwórz miesiąc ponownie.' },
    decorators: [
        (Story) => (
            <div style={{ maxWidth: 380 }}>
                <Tile kind="plain">
                    <TileHeader label="Raport" />
                    <Story />
                </Tile>
            </div>
        ),
    ],
} satisfies Meta<typeof Notice>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Lock: Story = {};

export const Warn: Story = {
    args: { kind: 'warn', children: 'Liczby wcześniejszego miesiąca się zmieniły. Wygeneruj raport ponownie: do tego czasu nie można go sfinalizować ani zamknąć miesiąca.' },
};

export const Info: Story = { args: { kind: 'info', children: 'Miesiąc w toku zamkniesz po jego zakończeniu.' } };

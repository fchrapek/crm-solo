import type { Meta, StoryObj } from '@storybook/react-vite';
import { SectionToggle } from './section-toggle';

const meta = {
    title: 'Solo/Section toggle',
    component: SectionToggle,
    args: {
        label: 'Checklista',
        summary: '2 z 4',
        children: <p style={{ margin: 0 }}>Eksport tabel do PDF, sprawdzenie cen netto.</p>,
    },
    decorators: [
        (Story) => (
            <div style={{ maxWidth: 760 }}>
                <Story />
            </div>
        ),
    ],
} satisfies Meta<typeof SectionToggle>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Collapsed: Story = {};

export const Open: Story = { args: { defaultOpen: true } };

export const WithActions: Story = {
    args: {
        label: 'Zasoby',
        summary: '1 plik · 1 link',
        phoneSummary: '2',
        extra: <a href="#dodaj">Dodaj</a>,
        phoneExtra: <a href="#dodaj">Dodaj</a>,
    },
};

export const Desktop: Story = { ...WithActions, globals: { viewport: { value: 'desktop', isRotated: false } } };

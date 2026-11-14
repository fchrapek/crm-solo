import type { Meta, StoryObj } from '@storybook/react-vite';
import { Alert } from './alert';

const meta = {
    title: 'Solo/Notice',
    component: Alert,
    args: { variant: 'warn', children: 'Wysyłka testowa: nic nie wyjdzie do klienta, dopóki nie ustawisz nadawcy.' },
    parameters: {
        docs: {
            description: {
                component: 'Alert with variant warn, error or ok: the Solo notice, a plain statement on the block colour (Figma: Notice / warn|error|ok).',
            },
        },
    },
} satisfies Meta<typeof Alert>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Warn: Story = {};

export const Error: Story = {
    args: { variant: 'error', children: 'Nie wysłano 02.10 o 14:20: serwer poczty nie przyjął adresu. Spróbuj ponownie.' },
};

export const Ok: Story = {
    args: { variant: 'ok', children: 'Wysłano 02.10.2026 o 14:20 do 2 osób. Wysłanego podsumowania nie da się zmienić.' },
};

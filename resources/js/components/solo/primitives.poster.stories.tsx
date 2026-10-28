import type { Meta, StoryObj } from '@storybook/react-vite';
import { Poster } from './primitives';

const meta = {
    title: 'Solo/Poster',
    component: Poster,
    args: { children: 'Piątek' },
} satisfies Meta<typeof Poster>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Heading: Story = {};

/** Display is for the moment screens only: GOTOWE. and GOTOWE? */
export const Display: Story = { args: { size: 'display', children: 'Gotowe.' } };

export const DisplayOnDone: Story = { args: { size: 'display', children: 'Gotowe.' }, globals: { day: 'done' } };

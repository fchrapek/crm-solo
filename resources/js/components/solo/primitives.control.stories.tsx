import type { Meta, StoryObj } from '@storybook/react-vite';
import { IconButton } from './icon-button';
import { SoloButton } from './primitives';
import { Tile } from './tile';

const meta = {
    title: 'Redesign/Button',
    component: SoloButton,
    argTypes: { variant: { control: 'select', options: ['primary', 'secondary', 'quiet', 'stop', 'on-colour'] } },
    args: { variant: 'primary', size: 'control', children: 'Zamknij dzień' },
} satisfies Meta<typeof SoloButton>;

export default meta;

type Story = StoryObj<typeof meta>;

/** One per tile at most. 44 px on a phone, 40 from 768 px. */
export const Primary: Story = {};

export const Secondary: Story = { args: { variant: 'secondary', children: 'Zaplanuj jutro' } };

export const Quiet: Story = { args: { variant: 'quiet', children: 'Anuluj' } };

/** Stops the running timer: the only vermilion button. */
export const Stop: Story = { args: { variant: 'stop', children: 'Stop' } };

export const Disabled: Story = { args: { disabled: true } };

/** Any button on a vermilion surface. */
export const OnColour: Story = {
    args: { variant: 'on-colour', children: 'Stop' },
    decorators: [
        (Story) => (
            <Tile kind="timer">
                <Story />
            </Tile>
        ),
    ],
};

export const IconQuiet: Story = { render: () => <IconButton icon="more" look="quiet" label="Więcej akcji" /> };

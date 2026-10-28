import type { Meta, StoryObj } from '@storybook/react-vite';
import { MonthCard, type MonthData, type TileState } from './month-card';

const DESKTOP = { viewport: { value: 'desktop', isRotated: false } };

/** October 2026 with the 23rd (a Friday) as today; overrides set single days by number. */
function october(today: 'today' | 'stamped', overrides: Record<number, TileState> = {}): MonthData {
    const days = Array.from({ length: 31 }, (_, i) => {
        const n = i + 1;
        const weekday = ((new Date(Date.UTC(2026, 9, n)).getUTCDay() + 6) % 7) + 1;
        const state: TileState = overrides[n] ?? (n === 23 ? today : n > 23 ? 'future' : weekday > 5 ? 'rest' : 'closed');

        return { date: `2026-10-${String(n).padStart(2, '0')}`, weekday, state };
    });
    const weekdays = days.filter((d) => d.weekday <= 5).length;

    return { month: '2026-10', days, closed: days.filter((d) => d.state === 'closed' || d.state === 'stamped').length, weekdays };
}

const meta = {
    title: 'Solo/Month card',
    component: MonthCard,
    args: { month: october('today', { 14: 'open', 20: 'open' }) },
    globals: DESKTOP,
    decorators: [
        (Story) => (
            <div style={{ width: 380 }}>
                <Story />
            </div>
        ),
    ],
    parameters: { docs: { description: { component: 'From 768 px only: phones draw no calendar, so these stories open on Desktop.' } } },
} satisfies Meta<typeof MonthCard>;

export default meta;

type Story = StoryObj<typeof meta>;

/** Side size, filling the 380 column: last week and this week, today in vermilion. */
export const Side: Story = {};

/** The day closed: today's tile keeps its edge and gets the check (Day tile State=Today closed). */
export const TodayClosed: Story = { args: { month: october('stamped', { 14: 'open', 20: 'open' }) } };

/** Every tile state in two weeks: closed, open, today, rest, future. */
export const EveryTile: Story = { args: { month: october('today', { 19: 'closed', 20: 'open', 21: 'closed', 22: 'open' }) } };

/** Dziś adds Cały miesiąc ↓, which opens the whole month in a popover. */
export const WithMonthLink: Story = { args: { monthLink: true } };

/** Plan jutro and Podsumowanie name the day in the head. */
export const WithDate: Story = { args: { date: '2026-10-23' } };

/** Surface=Accent: the card on Podsumowanie (Done) and the timer. */
export const OnDone: Story = { args: { month: october('stamped', { 14: 'open' }) }, globals: { ...DESKTOP, day: 'done' } };

export const OnTimer: Story = { globals: { ...DESKTOP, day: 'timer' } };
